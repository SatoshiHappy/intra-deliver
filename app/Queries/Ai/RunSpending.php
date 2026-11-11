<?php

namespace App\Queries\Ai;

use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Queries\Ai\Api\RunBudgetQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 予算の区分の使った額と上限の比べ方(正本 = docs の 設計/AI/実行と費用/接続先と費用.md 5.2)。依頼の口の上限の確認と、区分の状態の読み取りが
 * 同じ比べ方を使う。繰り越しを書く業務処理と退避から戻す道具も、数える行・額・時刻・東京の日を同じ式({@see self::spentRowsSql()}・
 * {@see self::carryOverRowsSql()})で数える(式を写すと片方だけが直るので 1 か所に置く)。会話の型は実行の行でなくステップの行から数える。
 * 表の名前は字の定数に置く(DB::raw が字の SQL を求めるため。繰り越しを書く業務処理も使い、モデルの表と同じことはテストが確かめる)。
 */
class RunSpending
{
    public const RUNS_TABLE = 'ai_runs';

    public const STEPS_TABLE = 'ai_run_steps';

    public const CARRYOVERS_TABLE = 'ai_spending_carryovers';

    public function __construct(private AiSettings $settings) {}

    /**
     * 区分の「使った額 + $extraUsd」が上限を超える期間(日・月・累計。複数なら長い方)。無ければ null。
     *
     * @param  string  $extraUsd  上乗せする額(小数 6 桁)。依頼の見積もりか、区分の見積もりの上限
     * @return RunBudgetQuery::DAY|RunBudgetQuery::MONTH|RunBudgetQuery::TOTAL|null
     *
     * @throws LogicException 設定に無い区分・数でない上限のとき
     */
    public function exceededPeriod(string $budget, DateTimeInterface $at, string $extraUsd): ?string
    {
        $limits = [
            RunBudgetQuery::TOTAL => $this->settings->budget($budget, 'total_usd'),
            RunBudgetQuery::MONTH => $this->settings->budget($budget, 'monthly_usd'),
            RunBudgetQuery::DAY => $this->settings->budget($budget, 'daily_usd'),
        ];

        $limited = array_keys(array_filter($limits, fn (?float $limit): bool => $limit !== null));

        if ($limited === []) {
            return null;
        }

        $local = CarbonImmutable::instance($at)->setTimezone(AccessContext::TIMEZONE);
        $starts = [
            RunBudgetQuery::TOTAL => null,
            RunBudgetQuery::MONTH => $local->startOfMonth(),
            RunBudgetQuery::DAY => $local->startOfDay(),
        ];
        $spent = $this->spentByPeriod($budget, $starts, $starts[$limited[0]]);

        foreach ($limits as $period => $limit) {
            if ($limit !== null && $this->exceeds($limit, $spent[$period], $extraUsd)) {
                return $period;
            }
        }

        return null;
    }

    /**
     * 使った額を数える行(列 = 区分 budget・実行の状態 status・数える時刻 counted_at・額 cost_usd)の SQL。数える行・時刻・額は
     * docs の 設計/AI/実行と費用/接続先と費用.md 5.2 の「使った額」。
     *
     * @param  literal-string  $runs  実行の表(か、数える列の型をそろえた見かけの表)の名前
     * @param  literal-string  $steps  ステップの表(か、同じく見かけの表)の名前
     * @return literal-string
     */
    public static function spentRowsSql(string $runs, string $steps): string
    {
        $conversation = "'".AiKindType::Conversation->value."'";

        return 'SELECT budget, status, created_at AS counted_at, coalesce(cost_usd, reserved_cost_usd) AS cost_usd'
            ." FROM {$runs} WHERE kind_type <> {$conversation}"
            .' UNION ALL SELECT r.budget, r.status, s.started_at, coalesce(s.cost_usd, s.reserved_cost_usd)'
            ." FROM {$steps} AS s JOIN {$runs} AS r ON r.id = s.ai_run_id WHERE r.kind_type = {$conversation}";
    }

    /**
     * 繰り越しの行(列 = 区分 budget・東京の日 spent_on・額 cost_usd)の SQL。除く実行と足し方は docs の 設計/AI/実行と費用/接続先と費用.md 5.2 の
     * 「繰り越し」と ADR 0024。引数は {@see self::spentRowsSql()} と同じ。
     *
     * @param  literal-string  $runs
     * @param  literal-string  $steps
     * @return literal-string
     */
    public static function carryOverRowsSql(string $runs, string $steps): string
    {
        return 'SELECT budget, '.self::daySql('counted_at').' AS spent_on, sum(cost_usd) AS cost_usd'
            .' FROM ('.self::spentRowsSql($runs, $steps).") AS spent WHERE status <> '".AiRunStatus::Queued->value."'"
            .' GROUP BY 1, 2';
    }

    /**
     * 時刻の列の東京の日(日の上限の期間と同じ暦)。
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function daySql(string $column): string
    {
        return "({$column} AT TIME ZONE '".AccessContext::TIMEZONE."')::date";
    }

    /** 使った額 + 上乗せが上限を超えるか(100 万分の 1 ドルの整数で比べる。ちょうど上限は超えない) */
    private function exceeds(float $limit, string $spentUsd, string $extraUsd): bool
    {
        $total = RunEstimates::micros($spentUsd) + RunEstimates::micros($extraUsd);

        return $total > RunEstimates::micros(RunEstimates::usd($limit));
    }

    /**
     * 期間ごとの使った額(数える時刻が期間の中の額の和と、期間の中の日の繰り越しの額の和。小数 6 桁)。日と月の期間は東京の日の始まりから
     * なので、繰り越しは東京の日付で比べる。3 つの期間を 1 回の問い合わせで数える(繰り越しも 1 回)。読む行は $since(上限のある期間の
     * うち最も古い始まり。累計に上限があれば null = 絞らない)から後に絞るので、それより長い期間の和は使えない値になる(上限が無いので使わない)。
     *
     * @param  array{total: null, month: CarbonImmutable, day: CarbonImmutable}  $starts  期間の始まり(東京)
     * @return array<RunBudgetQuery::DAY|RunBudgetQuery::MONTH|RunBudgetQuery::TOTAL, string>
     */
    private function spentByPeriod(string $budget, array $starts, ?CarbonImmutable $since): array
    {
        [$month, $day] = [$starts[RunBudgetQuery::MONTH], $starts[RunBudgetQuery::DAY]];
        $rows = self::spentRowsSql(self::RUNS_TABLE, self::STEPS_TABLE);
        $spent = DB::query()->from(DB::raw("({$rows}) AS spent"))->where('budget', $budget)
            ->when($since !== null, fn ($query) => $query->where('counted_at', '>=', $since?->utc()))
            ->selectRaw(self::periodSumsSql('counted_at'), [$month->utc(), $day->utc()])
            ->sole();
        $carried = DB::table(self::CARRYOVERS_TABLE)->where('budget', $budget)
            ->when($since !== null, fn ($query) => $query->where('spent_on', '>=', $since?->toDateString()))
            ->selectRaw(self::periodSumsSql('spent_on'), [$month->toDateString(), $day->toDateString()])
            ->sole();

        return [
            RunBudgetQuery::TOTAL => RunEstimates::usd((float) $spent->total + (float) $carried->total),
            RunBudgetQuery::MONTH => RunEstimates::usd((float) $spent->month + (float) $carried->month),
            RunBudgetQuery::DAY => RunEstimates::usd((float) $spent->day + (float) $carried->day),
        ];
    }

    /**
     * 額の累計・月・日の和の列(束ねの値 = 月の始まり・日の始まり)。
     *
     * @param  literal-string  $column  期間で比べる列
     * @return literal-string
     */
    private static function periodSumsSql(string $column): string
    {
        return "coalesce(sum(cost_usd), 0) AS total, coalesce(sum(cost_usd) FILTER (WHERE {$column} >= ?), 0) AS month,"
            ." coalesce(sum(cost_usd) FILTER (WHERE {$column} >= ?), 0) AS day";
    }
}

<?php

namespace App\Queries\Ai;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiSpendingCarryover;
use App\Queries\Ai\Api\RunBudgetQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 予算の区分の使った額と上限の比べ方(正本 = docs の 設計/AI/実行と費用.md 5.2)。依頼の口の上限の確認と、区分の状態の読み取りが同じ比べ方を使う。
 * 繰り越しを書く業務処理も、実行ごとの額と東京の日を同じ式({@see self::RUN_COST_SQL}・{@see self::runDaySql()})で数える。
 */
class RunSpending
{
    /** 実行 1 件の使った額(ai_runs の列の式。費用が分からない実行は見積もりで数え、上限をすり抜けない) */
    public const RUN_COST_SQL = 'coalesce(cost_usd, reserved_cost_usd)';

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
        $local = CarbonImmutable::instance($at)->setTimezone(AccessContext::TIMEZONE);
        $periods = [
            [RunBudgetQuery::TOTAL, 'total_usd', null],
            [RunBudgetQuery::MONTH, 'monthly_usd', $local->startOfMonth()],
            [RunBudgetQuery::DAY, 'daily_usd', $local->startOfDay()],
        ];

        foreach ($periods as [$period, $limitKey, $since]) {
            $limit = $this->settings->budget($budget, $limitKey);

            if ($limit !== null && $this->exceeds($limit, $this->spentSince($budget, $since), $extraUsd)) {
                return $period;
            }
        }

        return null;
    }

    /** 使った額 + 上乗せが上限を超えるか(100 万分の 1 ドルの整数で比べる。ちょうど上限は超えない) */
    private function exceeds(float $limit, string $spentUsd, string $extraUsd): bool
    {
        $total = RunEstimates::micros($spentUsd) + RunEstimates::micros($extraUsd);

        return $total > RunEstimates::micros(RunEstimates::usd($limit));
    }

    /**
     * 実行を依頼した東京の日(ai_runs の列の式。日の上限の期間と同じ暦 = Asia/Tokyo の 0:00 で切る)。
     */
    public static function runDaySql(): string
    {
        return "(created_at AT TIME ZONE '".AccessContext::TIMEZONE."')::date";
    }

    /**
     * 期間の中に依頼した実行の額の和と、期間の中の日の繰り越しの額の和(小数 6 桁)。$since が null なら全期間。
     * $since は東京の日の始まり(日と月の期間)なので、繰り越しは東京の日付で比べる。
     */
    private function spentSince(string $budget, ?CarbonImmutable $since): string
    {
        $runs = AiRun::query()
            ->where('budget', $budget)
            ->when($since !== null, fn ($query) => $query->where('created_at', '>=', $since?->utc()))
            ->sum(DB::raw(self::RUN_COST_SQL));
        $carried = AiSpendingCarryover::query()
            ->where('budget', $budget)
            ->when($since !== null, fn ($query) => $query->where('spent_on', '>=', $since?->toDateString()))
            ->sum('cost_usd');

        return RunEstimates::usd((float) $runs + (float) $carried);
    }
}

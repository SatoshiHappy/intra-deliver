<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\RunBudget;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Queries\Ai\Api\RunBudgetQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\TransactionalTestCase;

/**
 * 上限の確かめの使った額は、会話の型の実行をステップの行から数える(docs の 設計/AI/実行と費用/接続先と費用.md 5.2 の「使った額」= ステップの
 * 開始の時刻が期間の中のステップの費用。分からない間は見積もり)。守る結果 = 設計/AI/データと失敗と検査.md 8 節の「テスト」の「予算の区分の状態と
 * 依頼の口は同じ式で数える」。区分の状態は会話の型の種類を渡して(1 依頼の見積もりの上限 = 0)使った額だけで見て、依頼の口の確かめは
 * {@see RunBudget::ensureWithin()} に 0 の見積もりを渡して見る。額の刻みは 100 万分の 1 ドル。
 */
final class ConversationStepSpendingTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;
    use UsesTestPromptKinds;

    /** 既定の区分の超えた期間ごとの断りの文 */
    private const MESSAGES = [
        RunBudgetQuery::DAY => RunBudget::DAILY_EXCEEDED,
        RunBudgetQuery::MONTH => RunBudget::MONTHLY_EXCEEDED,
        RunBudgetQuery::TOTAL => RunBudget::TOTAL_EXCEEDED,
    ];

    /** 文章の依頼の見積もり(UsesTestPromptKinds::promptRequest) */
    private const PROMPT_RESERVED = 0.000270;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        $this->registerTestPromptKinds();
        $this->limits(null, null, null);
    }

    /**
     * ステップは開始の時刻の日に数える(実行を作った日でなく)。東京の 10/17 23:59:59(14:59:59+00)と 10/18 0:00(15:00:00+00)は別の日で、
     * 月と累計はどちらも数える。
     */
    public function test_steps_are_counted_on_the_tokyo_day_they_started(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00', 'Asia/Tokyo'));
        $run = $this->conversationRun('2026-10-16 10:00:00+00');
        $this->step($run, '2026-10-17 14:59:59+00', '1.000000', '0.300000');
        $this->step($run, '2026-10-17 15:00:00+00', '0.400000');

        $this->assertSpent(RunBudgetQuery::DAY, '0.400000');
        $this->assertSpent(RunBudgetQuery::MONTH, '0.700000');
        $this->assertSpent(RunBudgetQuery::TOTAL, '0.700000');
    }

    /** 月の境も東京の暦で切る(10/31 23:59:59 は 10 月、11/1 0:00 は 11 月) */
    public function test_steps_are_counted_in_the_tokyo_month_they_started(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00', 'Asia/Tokyo'));
        $run = $this->conversationRun('2026-10-31 10:00:00+00');
        $this->step($run, '2026-10-31 14:59:59+00', '0.250000');
        $this->step($run, '2026-10-31 15:00:00+00', '0.125000');

        $this->assertSpent(RunBudgetQuery::DAY, '0.125000');
        $this->assertSpent(RunBudgetQuery::MONTH, '0.125000');
        $this->assertSpent(RunBudgetQuery::TOTAL, '0.375000');
    }

    /**
     * 費用の分かったステップは費用(0 を含む)で、分からないステップ(処理中・使用量の分からない失敗と止めた)は見積もりで数える。
     * 文章の行と混ざる日はその和、ほかの区分のステップは数えない。
     */
    public function test_known_costs_count_by_the_cost_and_unknown_by_the_estimate_with_prompt_runs(): void
    {
        $run = $this->conversationRun();
        $this->step($run, now()->toIso8601String(), '2.000000', '0.100000');
        $this->step($run, now()->toIso8601String(), '3.000000', '0.000000');
        $this->step($run, now()->toIso8601String(), '0.200000');
        AiRunStep::factory()->failed()->create($this->stepAt($run, '0.040000'));
        AiRunStep::factory()->stopped()->create($this->stepAt($run, '0.005000'));
        AiRun::factory()->succeeded('1.000000')->create(['budget' => 'default', 'created_at' => now()]);
        $other = $this->conversationRun(budget: 'transcription');
        $this->step($other, now()->toIso8601String(), '9.000000');

        $this->assertSpent(RunBudgetQuery::DAY, '1.345000');
    }

    /**
     * ステップの費用だけで上限に届く日: 使った額が上限より 1 刻み少ない・上限と同じでは超えず、1 刻み多いと超えた期間を返す。区分の状態と
     * 依頼の口の確かめの両方で通す。
     */
    public function test_steps_alone_reach_the_limit_by_one_step_of_the_unit(): void
    {
        $this->limits(1, null, null);
        $run = $this->conversationRun();
        $this->step($run, now()->toIso8601String(), '0.999999');
        $this->assertExceeded(null);

        $this->step($run, now()->toIso8601String(), '0.000001');
        $this->assertExceeded(null);

        $this->step($run, now()->toIso8601String(), '0.000001');
        $this->assertExceeded(RunBudgetQuery::DAY);
    }

    /** 文章の依頼の口も、ステップの使った額 + 依頼の見積もりで上限を見る(ちょうど上限は受け、1 刻み超えると断る) */
    public function test_the_prompt_request_counts_the_steps(): void
    {
        Queue::fake();
        $this->step($this->conversationRun(), now()->toIso8601String(), '0.500000');

        $this->limits(round(0.5 + self::PROMPT_RESERVED - 0.000001, 6), null, null);
        try {
            app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
            $this->fail('上限を超える依頼が通った');
        } catch (ValidationException $exception) {
            $this->assertSame([RunBudget::ERROR_KEY => [RunBudget::DAILY_EXCEEDED]], $exception->errors());
        }

        $this->limits(round(0.5 + self::PROMPT_RESERVED, 6), null, null);
        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        $this->assertSame(1, AiRun::query()->where('kind', RecordingPromptKind::CODE)->count());
    }

    /**
     * 使った額がちょうど $usd(上限を $usd にすると超えず、1 刻み少なくすると $period を超える)。区分の状態と依頼の口の確かめの両方で見る。
     *
     * @param  RunBudgetQuery::DAY|RunBudgetQuery::MONTH|RunBudgetQuery::TOTAL  $period
     */
    private function assertSpent(string $period, string $usd): void
    {
        $limit = (float) $usd;
        $only = fn (float $value): array => [
            $period === RunBudgetQuery::DAY ? $value : null,
            $period === RunBudgetQuery::MONTH ? $value : null,
            $period === RunBudgetQuery::TOTAL ? $value : null,
        ];

        $this->limits(...$only($limit));
        $this->assertExceeded(null, "{$period} の使った額が {$usd} を超えた");

        $this->limits(...$only(round($limit - 0.000001, 6)));
        $this->assertExceeded($period, "{$period} の使った額が {$usd} より少ない");
        $this->limits(null, null, null);
    }

    /**
     * 区分の状態が超えた期間 $period を返し(null = 超えない)、依頼の口の確かめがその期間の文で断る(null なら断らない)。
     *
     * @param  RunBudgetQuery::DAY|RunBudgetQuery::MONTH|RunBudgetQuery::TOTAL|null  $period
     */
    private function assertExceeded(?string $period, string $message = ''): void
    {
        $query = app(RunBudgetQuery::class);
        $state = $query->exceededPeriodOfKind(RecordingConversationKind::CODE, CarbonImmutable::now());

        try {
            app(RunBudget::class)->ensureWithin('default', '0.000000');
            $declined = null;
        } catch (RunRequestDeclined $exception) {
            $declined = [$exception->reason, $exception->errors()];
        }

        $expected = $period === null
            ? null
            : [RunRequestDeclined::BUDGET, [RunBudget::ERROR_KEY => [self::MESSAGES[$period]]]];
        $this->assertSame([$period, $expected], [$state, $declined], $message);
    }

    private function limits(?float $daily, ?float $monthly, ?float $total): void
    {
        config(['ai_runs.budget.default' => ['daily_usd' => $daily, 'monthly_usd' => $monthly, 'total_usd' => $total]]);
    }

    /** 処理中の会話の型の実行(作った時刻は UTC の文字列。既定は今) */
    private function conversationRun(?string $createdAt = null, string $budget = 'default'): AiRun
    {
        return AiRun::factory()->conversation()->running()->create([
            'kind' => RecordingConversationKind::CODE,
            'budget' => $budget,
            'created_at' => $createdAt === null ? now() : CarbonImmutable::parse($createdAt),
        ]);
    }

    /** ステップ(費用を渡せば使用量の分かった成功、渡さなければ処理中) */
    private function step(AiRun $run, string $startedAt, string $reservedUsd, ?string $costUsd = null): void
    {
        $factory = $costUsd === null ? AiRunStep::factory() : AiRunStep::factory()->succeeded($costUsd);
        $factory->create($this->stepAt($run, $reservedUsd, $startedAt));
    }

    /**
     * ステップの実行・見積もり・開始の時刻。
     *
     * @return array<string, mixed>
     */
    private function stepAt(AiRun $run, string $reservedUsd, ?string $startedAt = null): array
    {
        return [
            'ai_run_id' => $run->id,
            'reserved_cost_usd' => $reservedUsd,
            'started_at' => $startedAt === null ? now() : CarbonImmutable::parse($startedAt),
        ];
    }
}

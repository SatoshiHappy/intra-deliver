<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiSpendingCarryover;
use App\Queries\Ai\Api\RunBudgetQuery;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 予算の区分の状態(docs の 設計/AI/公開の口.md 3 節の「予算の区分の状態」、実行と費用.md 5.2)。
 */
final class RunBudgetQueryTest extends TransactionalTestCase
{
    /** default の区分の見積もりの上限の最大(((1,000 × 2 + 2,000)× 0.10 + 1,000 × 0.50)/ 100 万) */
    private const CEILING = 0.000900;

    protected function setUp(): void
    {
        parent::setUp();
        $kind = [
            'type' => 'prompt',
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'timeout_seconds' => 100,
            'max_output_tokens' => 100,
            'max_input_chars' => 1000,
            'provider_options' => [],
        ];
        config([
            'ai_runs.kinds' => [
                'test.small' => $kind,
                'test.large' => [...$kind, 'max_output_tokens' => 1000],
                'test.other' => [...$kind, 'budget' => 'transcription', 'max_output_tokens' => 100000],
            ],
            'ai_runs.budget' => [
                'default' => ['daily_usd' => 10, 'monthly_usd' => 10, 'total_usd' => 10],
                'transcription' => ['daily_usd' => 10, 'monthly_usd' => 10, 'total_usd' => 10],
                'unused' => ['daily_usd' => 1, 'monthly_usd' => null, 'total_usd' => null],
            ],
        ]);
    }

    public function test_below_every_limit_is_none(): void
    {
        $this->spend('1.000000');

        $this->assertNull($this->exceededPeriod('default'));
    }

    public function test_the_spent_plus_the_ceiling_decides_not_the_spent_alone(): void
    {
        $this->spend('1.000000');

        $this->setLimit('total_usd', 1.0 + self::CEILING);
        $this->assertNull($this->exceededPeriod('default'));

        $this->setLimit('total_usd', 1.0 + self::CEILING - 0.000001);
        $this->assertSame(RunBudgetQuery::TOTAL, $this->exceededPeriod('default'));
    }

    public function test_the_longest_exceeded_period_is_returned(): void
    {
        $this->spend('1.000000');

        $this->setLimit('daily_usd', 1.0);
        $this->assertSame(RunBudgetQuery::DAY, $this->exceededPeriod('default'));

        $this->setLimit('monthly_usd', 1.0);
        $this->assertSame(RunBudgetQuery::MONTH, $this->exceededPeriod('default'));

        $this->setLimit('total_usd', 1.0);
        $this->assertSame(RunBudgetQuery::TOTAL, $this->exceededPeriod('default'));
    }

    public function test_unfinished_and_unknown_usage_runs_are_counted_by_the_estimate_and_other_budgets_are_not(): void
    {
        $this->aiRun(['reserved_cost_usd' => '0.500000']);
        $this->aiRun(['reserved_cost_usd' => '0.250000', 'status' => AiRunStatus::Failed]);
        $this->aiRun(['reserved_cost_usd' => '5.000000', 'budget' => 'transcription']);

        $this->setLimit('total_usd', 0.75 + self::CEILING);
        $this->assertNull($this->exceededPeriod('default'));

        $this->setLimit('total_usd', 0.75 + self::CEILING - 0.000001);
        $this->assertSame(RunBudgetQuery::TOTAL, $this->exceededPeriod('default'));
    }

    public function test_the_day_and_the_month_are_cut_by_the_tokyo_calendar(): void
    {
        $this->spend('1.000000', CarbonImmutable::parse('2026-09-30 23:59:59', 'Asia/Tokyo'));
        $this->spend('0.500000', CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Tokyo'));
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'Asia/Tokyo');

        $this->setLimit('monthly_usd', 0.5 + self::CEILING);
        $this->assertNull($this->exceededPeriod('default', $at));

        $this->setLimit('monthly_usd', 0.5 + self::CEILING - 0.000001);
        $this->assertSame(RunBudgetQuery::MONTH, $this->exceededPeriod('default', $at));

        $this->setLimit('monthly_usd', 10);
        $this->setLimit('daily_usd', 0.5 + self::CEILING);
        $this->assertNull($this->exceededPeriod('default', $at));

        $this->setLimit('daily_usd', 0.5 + self::CEILING - 0.000001);
        $this->assertSame(RunBudgetQuery::DAY, $this->exceededPeriod('default', $at));
    }

    public function test_the_day_before_midnight_in_tokyo_is_not_counted_for_the_day(): void
    {
        $this->spend('1.000000', CarbonImmutable::parse('2026-10-17 23:59:59', 'Asia/Tokyo'));
        $this->setLimit('daily_usd', 0.5);

        $this->assertNull($this->exceededPeriod('default', CarbonImmutable::parse('2026-10-18 00:30', 'Asia/Tokyo')));
    }

    /**
     * 5.2 の使った額のうち繰り越しの分(日・月・累計の切り方と、ほかの区分の分)。
     */
    public function test_the_carryover_is_counted_by_its_tokyo_day_for_the_day_the_month_and_the_total(): void
    {
        $this->carry('2026-09-30', '1.000000');
        $this->carry('2026-10-01', '0.250000');
        $this->carry('2026-10-01', '5.000000', 'transcription');
        $this->spend('0.250000', CarbonImmutable::parse('2026-10-01 07:00', 'Asia/Tokyo'));
        $at = CarbonImmutable::parse('2026-10-01 08:00', 'Asia/Tokyo');
        $expected = [
            ['daily_usd', 0.5, RunBudgetQuery::DAY],
            ['monthly_usd', 0.5, RunBudgetQuery::MONTH],
            ['total_usd', 1.5, RunBudgetQuery::TOTAL],
        ];

        foreach ($expected as [$period, $spent, $exceeded]) {
            $this->setLimit($period, $spent + self::CEILING);
            $this->assertNull($this->exceededPeriod('default', $at), $period);

            $this->setLimit($period, $spent + self::CEILING - 0.000001);
            $this->assertSame($exceeded, $this->exceededPeriod('default', $at), $period);
            $this->setLimit($period, 10);
        }
    }

    public function test_a_budget_without_kinds_has_a_ceiling_of_zero(): void
    {
        $this->spend('1.000000', null, 'unused');

        $this->setLimit('daily_usd', 1.0, 'unused');
        $this->assertNull($this->exceededPeriod('unused'));

        $this->setLimit('daily_usd', 0.999999, 'unused');
        $this->assertSame(RunBudgetQuery::DAY, $this->exceededPeriod('unused'));
    }

    /**
     * 文字起こしの種類も区分の見積もりの上限に入る(1 時間 × 1 分あたり 0.005 ドル = 0.30 ドル。区分の文章の種類 test.other の
     * ((1,000 × 2 + 2,000)× 0.10 + 100,000 × 0.50)/ 100 万 = 0.0504 ドルより大きい)。
     */
    public function test_the_ceiling_of_a_budget_includes_its_transcription_kinds(): void
    {
        config(['ai_runs.kinds' => [...(array) config('ai_runs.kinds'), 'test.audio' => [
            'type' => 'transcription',
            'provider' => 'gemini',
            'model' => 'gemini-3.5-transcribe',
            'budget' => 'transcription',
            'timeout_seconds' => 180,
            'language' => 'ja',
            'max_audio_seconds' => 3600,
            'chunk_seconds' => 1500,
        ]]]);
        $this->spend('1.000000', null, 'transcription');

        $this->setLimit('total_usd', 1.3, 'transcription');
        $this->assertNull($this->exceededPeriod('transcription'));

        $this->setLimit('total_usd', 1.299999, 'transcription');
        $this->assertSame(RunBudgetQuery::TOTAL, $this->exceededPeriod('transcription'));
    }

    public function test_a_budget_without_limits_is_none(): void
    {
        $this->spend('999999.000000');
        config(['ai_runs.budget.default' => ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null]]);

        $this->assertNull($this->exceededPeriod('default'));
    }

    public function test_a_budget_that_is_not_in_the_settings_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);

        $this->exceededPeriod('missing');
    }

    /**
     * 種類のコードから区分を引く(文章の種類 test.small = default、文字起こしの種類 test.audio = transcription)。返すものは区分の状態と同じ。
     */
    public function test_the_state_of_a_kind_is_the_state_of_its_budget(): void
    {
        config(['ai_runs.kinds' => [...(array) config('ai_runs.kinds'), 'test.audio' => [
            'type' => 'transcription',
            'provider' => 'gemini',
            'model' => 'gemini-3.5-transcribe',
            'budget' => 'transcription',
            'timeout_seconds' => 180,
            'language' => 'ja',
            'max_audio_seconds' => 60,
            'chunk_seconds' => 30,
        ]]]);
        $this->spend('1.000000');
        $this->setLimit('daily_usd', 1.0);
        $query = app(RunBudgetQuery::class);
        $now = CarbonImmutable::now();

        $this->assertSame(RunBudgetQuery::DAY, $query->exceededPeriodOfKind('test.small', $now));
        $this->assertNull($query->exceededPeriodOfKind('test.audio', $now));

        $this->spend('1.000000', null, 'transcription');
        $this->setLimit('monthly_usd', 1.0, 'transcription');
        $this->assertSame(RunBudgetQuery::MONTH, $query->exceededPeriodOfKind('test.audio', $now));
    }

    public function test_a_kind_that_is_not_in_the_settings_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);

        app(RunBudgetQuery::class)->exceededPeriodOfKind('test.missing', CarbonImmutable::now());
    }

    private function exceededPeriod(string $budget, ?CarbonImmutable $at = null): ?string
    {
        return app(RunBudgetQuery::class)->exceededPeriod($budget, $at ?? CarbonImmutable::now());
    }

    private function setLimit(string $period, float $limit, string $budget = 'default'): void
    {
        config(["ai_runs.budget.{$budget}.{$period}" => round($limit, 6)]);
    }

    /**
     * 使用量の分かった実行(費用 = $costUsd。見積もりは費用より大きく、費用が優先されることも見る)。
     */
    private function spend(string $costUsd, ?CarbonImmutable $createdAt = null, string $budget = 'default'): void
    {
        AiRun::factory()->succeeded($costUsd)->create([
            'budget' => $budget,
            'reserved_cost_usd' => '50.000000',
            'created_at' => ($createdAt ?? CarbonImmutable::now())->utc(),
        ]);
    }

    private function carry(string $day, string $costUsd, string $budget = 'default'): void
    {
        AiSpendingCarryover::query()->insert(['budget' => $budget, 'spent_on' => $day, 'cost_usd' => $costUsd]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function aiRun(array $attributes): void
    {
        $failed = ($attributes['status'] ?? null) === AiRunStatus::Failed;
        $factory = $failed ? AiRun::factory()->failed() : AiRun::factory();
        unset($attributes['status']);
        $factory->create(['budget' => 'default', ...$attributes, 'created_at' => now()]);
    }
}

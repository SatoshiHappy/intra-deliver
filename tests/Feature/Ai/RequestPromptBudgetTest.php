<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\RunBudget;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 費用の上限の境界(docs の 設計/AI/実行と費用.md 5.2、データと失敗と検査.md 8 節): 区分の期間ごとに「使った額 + この依頼の見積もり(0.000270)」が
 * 上限ちょうどなら通り、超えると 422(キー ai。区分と期間ごとの文)。使った額は同じ区分の coalesce(cost_usd, reserved_cost_usd) の和 =
 * 終わる前の実行と、使用量が分からないまま失敗した実行は見積もりで数え、ほかの区分の実行は数えない。日と月は Asia/Tokyo の暦で切る。
 */
final class RequestPromptBudgetTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    /** 依頼の見積もり(UsesTestPromptKinds::promptRequest) */
    private const RESERVED = 0.000270;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        Queue::fake();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function periods(): array
    {
        return [
            '日' => ['daily_usd', RunBudget::DAILY_EXCEEDED],
            '月' => ['monthly_usd', RunBudget::MONTHLY_EXCEEDED],
            '累計' => ['total_usd', RunBudget::TOTAL_EXCEEDED],
        ];
    }

    #[DataProvider('periods')]
    public function test_spent_plus_the_estimate_up_to_the_limit_passes_and_beyond_is_rejected(string $period, string $message): void
    {
        $spent = $this->spendWithEveryKindOfRun(now());

        $this->setBudget($period, $spent + self::RESERVED - 0.000001);
        $this->assertRejectedWith($message);

        $this->setBudget($period, $spent + self::RESERVED);
        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        $this->assertSame(4, AiRun::query()->count());
    }

    public function test_the_runs_of_another_budget_are_not_counted(): void
    {
        AiRun::factory()->succeeded('9.000000')->create(['budget' => 'transcription']);

        $this->setBudget('daily_usd', self::RESERVED);
        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());

        $this->assertSame(2, AiRun::query()->count());
    }

    public function test_the_message_is_chosen_by_the_budget_and_the_period(): void
    {
        $this->setKindSetting(RecordingPromptKind::CODE, 'budget', 'transcription');
        $expected = [
            'daily_usd' => RunBudget::TRANSCRIPTION_DAILY_EXCEEDED,
            'monthly_usd' => RunBudget::TRANSCRIPTION_MONTHLY_EXCEEDED,
            'total_usd' => RunBudget::TRANSCRIPTION_TOTAL_EXCEEDED,
        ];

        foreach ($expected as $period => $message) {
            $this->setBudget($period, 0.0, 'transcription');
            $this->assertRejectedWith($message);
            $this->setBudget($period, 1.0, 'transcription');
        }
    }

    public function test_a_budget_without_a_limit_accepts_any_amount(): void
    {
        $this->aiRun(['created_at' => now(), 'cost' => '999999.000000']);
        config(['ai_runs.budget.default' => ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null]]);

        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());

        $this->assertSame(2, AiRun::query()->count());
    }

    public function test_a_kind_whose_budget_is_not_in_the_settings_is_a_logic_error(): void
    {
        $this->setKindSetting(RecordingPromptKind::CODE, 'budget', 'unknown');

        $this->expectException(LogicException::class);

        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
    }

    public function test_the_day_is_cut_by_the_tokyo_calendar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 00:30', 'Asia/Tokyo'));
        $this->aiRun(['created_at' => CarbonImmutable::parse('2026-10-17 23:59:59', 'Asia/Tokyo'), 'cost' => '1.000000']);
        $this->aiRun(['created_at' => CarbonImmutable::parse('2026-10-18 00:00:00', 'Asia/Tokyo'), 'cost' => '0.500000']);

        $this->setBudget('daily_usd', 0.5 + self::RESERVED - 0.000001);
        $this->assertRejectedWith(RunBudget::DAILY_EXCEEDED);

        $this->setBudget('daily_usd', 0.5 + self::RESERVED);
        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        $this->addToAssertionCount(1);
    }

    public function test_the_month_is_cut_by_the_tokyo_calendar(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'Asia/Tokyo'));
        $this->aiRun(['created_at' => CarbonImmutable::parse('2026-09-30 23:59:59', 'Asia/Tokyo'), 'cost' => '1.000000']);
        $this->aiRun(['created_at' => CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Tokyo'), 'cost' => '0.500000']);

        $this->setBudget('monthly_usd', 0.5 + self::RESERVED - 0.000001);
        $this->assertRejectedWith(RunBudget::MONTHLY_EXCEEDED);

        $this->setBudget('monthly_usd', 0.5 + self::RESERVED);
        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        $this->addToAssertionCount(1);
    }

    public function test_the_longest_exceeded_period_chooses_the_message(): void
    {
        $spent = $this->spendWithEveryKindOfRun(now());
        config(['ai_runs.budget.default' => ['daily_usd' => $spent, 'monthly_usd' => $spent, 'total_usd' => $spent]]);

        $this->assertRejectedWith(RunBudget::TOTAL_EXCEEDED);
    }

    public function test_a_numeric_string_is_a_limit_and_a_non_numeric_value_is_a_logic_error(): void
    {
        config(['ai_runs.budget.default.daily_usd' => '0.000269']);
        $this->assertRejectedWith(RunBudget::DAILY_EXCEEDED);

        config(['ai_runs.budget.default.daily_usd' => 'unlimited']);
        $this->expectException(LogicException::class);
        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
    }

    /**
     * 使った額 1.75 = 成功(費用 1.0。見積もり 2.0 は数えない)+ 終わる前(見積もり 0.5)+ 使用量が分からない失敗(見積もり 0.25)。
     */
    private function spendWithEveryKindOfRun(CarbonImmutable $at): float
    {
        $this->aiRun(['created_at' => $at, 'cost' => '1.000000', 'reserved' => '2.000000']);
        $this->aiRun(['created_at' => $at, 'reserved' => '0.500000', 'status' => AiRunStatus::Running]);
        $this->aiRun(['created_at' => $at, 'reserved' => '0.250000', 'status' => AiRunStatus::Failed]);

        return 1.75;
    }

    /**
     * 実行の行(時刻は UTC にして書く。アプリと DB のタイムゾーンは UTC)。
     *
     * @param  array{created_at: CarbonImmutable, cost?: string, reserved?: string, status?: AiRunStatus}  $attributes
     */
    private function aiRun(array $attributes): void
    {
        $at = $attributes['created_at']->utc();
        $factory = AiRun::factory()->state(['reserved_cost_usd' => $attributes['reserved'] ?? '0.100000', 'created_at' => $at]);
        $factory = match (true) {
            isset($attributes['cost']) => $factory->succeeded($attributes['cost']),
            ($attributes['status'] ?? null) === AiRunStatus::Failed => $factory->failed(),
            ($attributes['status'] ?? null) === AiRunStatus::Running => $factory->running(),
            default => $factory,
        };
        $factory->create();
    }

    private function setBudget(string $period, float $limit, string $budget = 'default'): void
    {
        config(["ai_runs.budget.{$budget}.{$period}" => round($limit, 6)]);
    }

    private function assertRejectedWith(string $message): void
    {
        try {
            app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
            $this->fail('上限を超える依頼が通った');
        } catch (ValidationException $exception) {
            $this->assertSame([RunBudget::ERROR_KEY => [$message]], $exception->errors());
        }
    }
}

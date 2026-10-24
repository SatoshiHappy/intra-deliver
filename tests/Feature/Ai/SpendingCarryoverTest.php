<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\CarryOverSpending;
use App\Actions\Reset\Api\ResetParticipant;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiSpendingCarryover;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * AI の費用の繰り越し(docs の 設計/AI/実行と費用.md 5.2、口の保証 = 設計/公開の配備/毎日の初期化.md 7 節)。繰り越しの表の制約の検査も含む。
 */
final class SpendingCarryoverTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_the_ai_implementation_is_registered_and_keeps_the_carryover_table(): void
    {
        $participants = iterator_to_array($this->app->tagged(ResetParticipant::TAG), false);
        $ours = array_values(array_filter($participants, fn (object $item) => $item instanceof CarryOverSpending));

        $this->assertCount(1, $ours);
        $this->assertSame(['ai_spending_carryovers'], $ours[0]->keptTables());
    }

    /**
     * 東京の 10/17 23:59:59 と 10/18 0:00:00 は別の日(5.2)。
     */
    public function test_carries_the_spent_amount_by_budget_and_tokyo_day(): void
    {
        $before = '2026-10-17 23:59:59';
        $midnight = '2026-10-18 00:00:00';
        $this->aiRun('default', $before, AiRunStatus::Succeeded, '2.000000', '1.000000');
        $this->aiRun('default', $midnight, AiRunStatus::Succeeded, '2.000000', '0.500000');
        $this->aiRun('default', $midnight, AiRunStatus::Running, '0.250000');
        $this->aiRun('default', $midnight, AiRunStatus::Failed, '0.125000');
        $this->aiRun('transcription', $midnight, AiRunStatus::Succeeded, '9.000000', '3.000000');

        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([
            ['default', '2026-10-17', '1.000000'],
            ['default', '2026-10-18', '0.875000'],
            ['transcription', '2026-10-18', '3.000000'],
        ], $this->carryovers());
    }

    public function test_queued_runs_are_not_carried(): void
    {
        $at = '2026-10-18 12:00';
        $this->aiRun('default', $at, AiRunStatus::Queued, '5.000000');
        $this->aiRun('transcription', $at, AiRunStatus::Queued, '5.000000');
        $this->aiRun('transcription', $at, AiRunStatus::Running, '0.500000');

        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([['transcription', '2026-10-18', '0.500000']], $this->carryovers());
    }

    public function test_adds_to_the_row_of_the_same_budget_and_day(): void
    {
        $this->carry('default', '2026-10-18', '1.250000');
        $this->carry('default', '2026-10-17', '4.000000');
        $this->aiRun('default', '2026-10-18 08:00', AiRunStatus::Succeeded, '1.000000', '0.500000');

        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([
            ['default', '2026-10-17', '4.000000'],
            ['default', '2026-10-18', '1.750000'],
        ], $this->carryovers());
    }

    public function test_without_runs_nothing_is_carried(): void
    {
        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([], $this->carryovers());
    }

    public function test_runs_in_the_transaction_of_the_caller_and_rolls_back_with_it(): void
    {
        $this->aiRun('default', '2026-10-18 08:00', AiRunStatus::Succeeded, '1.000000', '0.500000');

        try {
            DB::transaction(function (): void {
                app(CarryOverSpending::class)->beforeTruncate();
                $this->assertCount(1, $this->carryovers());

                throw new RuntimeException('入れ直しの失敗');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->carryovers());
    }

    public function test_the_carryover_table_keeps_one_row_per_budget_and_day_with_a_non_negative_amount(): void
    {
        $this->carry('default', '2026-10-18', '1.000000');

        $table = 'ai_spending_carryovers';
        $this->assertRejectedBy("{$table}_pkey", fn () => $this->carry('default', '2026-10-18', '2.000000'));
        $this->assertRejectedBy("{$table}_cost_usd_check", fn () => $this->carry('default', '2026-10-19', '-0.000001'));
        $this->assertRejectedBy("{$table}_budget_check", fn () => $this->carry('', '2026-10-19', '1.000000'));
        $this->assertRejectedAsNull($table, 'cost_usd', fn () => $this->carry('default', '2026-10-20', null));

        $this->carry('transcription', '2026-10-18', '0.000000');
        $this->assertCount(2, $this->carryovers());
    }

    /**
     * 実行の行(東京の時刻 $tokyoTime を UTC にして書く)。費用 $costUsd は成功の行だけに付ける。
     */
    private function aiRun(
        string $budget,
        string $tokyoTime,
        AiRunStatus $status,
        string $reservedUsd,
        ?string $costUsd = null,
    ): void {
        $at = CarbonImmutable::parse($tokyoTime, 'Asia/Tokyo');
        $factory = match ($status) {
            AiRunStatus::Succeeded => AiRun::factory()->succeeded((string) $costUsd),
            AiRunStatus::Running => AiRun::factory()->running(),
            AiRunStatus::Failed => AiRun::factory()->failed(),
            AiRunStatus::Queued => AiRun::factory(),
        };
        $factory->create(['budget' => $budget, 'reserved_cost_usd' => $reservedUsd, 'created_at' => $at->utc()]);
    }

    private function carry(string $budget, string $day, ?string $costUsd): void
    {
        AiSpendingCarryover::query()->insert(['budget' => $budget, 'spent_on' => $day, 'cost_usd' => $costUsd]);
    }

    /**
     * 繰り越しの行(区分・日の順)。
     *
     * @return list<array{string, string, string}>
     */
    private function carryovers(): array
    {
        $rows = AiSpendingCarryover::query()->orderBy('budget')->orderBy('spent_on')->get();

        return array_values($rows->map(
            fn (AiSpendingCarryover $row): array => [$row->budget, $row->spent_on->toDateString(), $row->cost_usd],
        )->all());
    }
}

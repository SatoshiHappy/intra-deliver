<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\CarryOverSpending;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiSpendingCarryover;
use App\Queries\Ai\RunSpending;
use Carbon\CarbonImmutable;
use Tests\Feature\Reset\EnablesDailyResetBeforeBoot;
use Tests\Feature\Reset\PreparesReset;
use Tests\Feature\Reset\RunsCommands;
use Tests\Support\TransactionalTestCase;

/**
 * 毎日の初期化の繰り越しは、会話の型の実行をステップの行から数える(docs の 設計/AI/実行と費用/接続先と費用.md 5.2 の「繰り越し」、方式 =
 * ADR 0024): ステップの開始の時刻の東京の日で、実行の区分ごとに足し込む(費用が分からなければ見積もり(処理中を含む)、状態コード 400 以上は 0。
 * 待ちの実行は数えない)。
 */
final class ConversationStepCarryoverTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use PreparesReset;
    use RunsCommands;

    /**
     * 東京の 10/17 23:59:59(14:59:59+00)と 10/18 0:00(15:00:00+00)のステップは別の日に足す(実行を作った日でなく)。ステップだけの日・
     * 文章の行と混ざる日・ほかの区分を含む。
     */
    public function test_steps_are_carried_by_budget_and_the_tokyo_day_they_started(): void
    {
        $run = $this->conversationRun('2026-10-16 10:00:00+00');
        $this->step($run, '2026-10-17 14:59:59+00', '1.000000', '0.300000');
        $this->step($run, '2026-10-17 15:00:00+00', '0.400000');
        $this->step($run, '2026-10-17 15:00:00+00', '2.000000', '0.000000');
        $other = $this->conversationRun('2026-10-17 15:00:00+00', budget: 'transcription');
        $this->step($other, '2026-10-17 16:00:00+00', '0.125000');
        AiRun::factory()->succeeded('1.000000')->create([
            'budget' => 'default',
            'created_at' => CarbonImmutable::parse('2026-10-17 15:30:00+00'),
        ]);

        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([
            ['default', '2026-10-17', '0.300000'],
            ['default', '2026-10-18', '1.400000'],
            ['transcription', '2026-10-18', '0.125000'],
        ], $this->carryovers());
    }

    /**
     * 繰り越しの場面の数え方(5.2 の会話の型の表): 処理中のステップは見積もり(上限の確かめでは数えない行)、状態コード 400 以上の費用の分からない
     * ステップと文章の実行は 0、399 は見積もり。
     */
    public function test_running_steps_count_by_the_estimate_and_declined_rows_by_zero(): void
    {
        $run = $this->conversationRun('2026-10-18 01:00:00+00');
        $at = ['ai_run_id' => $run->id, 'started_at' => CarbonImmutable::parse('2026-10-18 01:00:00+00')];
        AiRunStep::factory()->create([...$at, 'reserved_cost_usd' => '0.700000']);
        AiRunStep::factory()->failed()->create([...$at, 'reserved_cost_usd' => '3.000000', 'provider_status' => 400]);
        AiRunStep::factory()->failed()->create([...$at, 'reserved_cost_usd' => '0.050000', 'provider_status' => 399]);
        AiRun::factory()->failed()->create([
            'budget' => 'default',
            'reserved_cost_usd' => '2.000000',
            'provider_status' => 529,
            'created_at' => CarbonImmutable::parse('2026-10-18 01:00:00+00'),
        ]);

        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([['default', '2026-10-18', '0.750000']], $this->carryovers());
    }

    /** その日の繰り越しの行が既にあれば、ステップの分を足す(ON CONFLICT の側) */
    public function test_steps_are_added_to_the_existing_row_of_the_day(): void
    {
        $carried = ['budget' => 'default', 'spent_on' => '2026-10-18', 'cost_usd' => '1.250000'];
        AiSpendingCarryover::query()->insert($carried);
        $this->step($this->conversationRun(), '2026-10-18 01:00:00+00', '0.500000');

        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([['default', '2026-10-18', '1.750000']], $this->carryovers());
    }

    /**
     * 待ちの実行のステップは数えない。ステップの無い会話の型の実行だけの日は行を作らない(実行の行は数えない)。
     */
    public function test_steps_of_queued_runs_and_conversation_runs_without_steps_are_not_carried(): void
    {
        $at = CarbonImmutable::parse('2026-10-18 01:00:00+00');
        $queued = AiRun::factory()->conversation()->create(['created_at' => $at]);
        $this->assertSame(AiRunStatus::Queued, $queued->status);
        $this->step($queued, '2026-10-18 01:00:00+00', '5.000000');
        $this->conversationRun('2026-10-19 01:00:00+00');

        app(CarryOverSpending::class)->beforeTruncate();

        $this->assertSame([], $this->carryovers());
    }

    /** 使った額と繰り越しの式が使う表の名前の字の定数(RunSpending。CarryOverSpending も使う)は、モデルの表と同じ */
    public function test_the_table_names_of_the_expressions_are_the_tables_of_the_models(): void
    {
        $this->assertSame(
            [(new AiRun)->getTable(), (new AiRunStep)->getTable(), (new AiSpendingCarryover)->getTable()],
            [RunSpending::RUNS_TABLE, RunSpending::STEPS_TABLE, RunSpending::CARRYOVERS_TABLE],
        );
    }

    /** 毎日の初期化の全体で、実行の記録を消す前の繰り越しにステップの分が入る(時刻は PreparesReset の東京の 10/18 9:00) */
    public function test_the_daily_reset_carries_the_steps(): void
    {
        $this->prepareReset();
        $this->step($this->conversationRun(), '2026-10-17 15:00:00+00', '0.250000', '0.200000');
        $this->step($this->conversationRun(), '2026-10-17 14:00:00+00', '0.500000');

        $this->assertCommand('reset:data', 0, '成否: 成功');

        $this->assertSame(
            [['default', '2026-10-17', '0.500000'], ['default', '2026-10-18', '0.200000']],
            $this->carryovers(),
        );
        $this->assertSame([0, 0], [AiRun::query()->count(), AiRunStep::query()->count()]);
    }

    /** 処理中の会話の型の実行(作った時刻は UTC の文字列。既定は今) */
    private function conversationRun(?string $createdAt = null, string $budget = 'default'): AiRun
    {
        return AiRun::factory()->conversation()->running()->create([
            'budget' => $budget,
            'created_at' => $createdAt === null ? now() : CarbonImmutable::parse($createdAt),
        ]);
    }

    /** ステップ(費用を渡せば使用量の分かった成功、渡さなければ費用の分からない止めた。開始の時刻は UTC の文字列) */
    private function step(AiRun $run, string $startedAt, string $reservedUsd, ?string $costUsd = null): void
    {
        $factory = $costUsd === null ? AiRunStep::factory()->stopped() : AiRunStep::factory()->succeeded($costUsd);
        $factory->create([
            'ai_run_id' => $run->id,
            'reserved_cost_usd' => $reservedUsd,
            'started_at' => CarbonImmutable::parse($startedAt),
        ]);
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

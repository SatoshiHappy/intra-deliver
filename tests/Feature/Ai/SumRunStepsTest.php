<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\SumRunSteps;
use App\Enums\Ai\AiRunStepSendKind;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の型の実行の表示用の見積もり・費用・使用量の和(docs の 設計/AI/実行の記録.md 2.1 の「会話の型が持つもの」の「見積もり・使用量・費用の和」):
 * 見積もりの和はどのステップも、費用と使用量の和は使用量が分かったステップだけ(失敗でも)。足すステップが無ければ 0。失敗の経路で実行の行に
 * 書くことは {@see ConversationFailureCleanUpTest}。
 */
final class SumRunStepsTest extends TransactionalTestCase
{
    private const ZERO_USAGE = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_read_input_tokens' => 0,
        'cache_write_input_tokens' => 0,
        'reasoning_tokens' => 0,
        'code_execution_seconds' => 0.0,
    ];

    public function test_the_estimates_of_every_step_and_the_costs_and_usage_of_the_known_steps_are_summed(): void
    {
        $run = AiRun::factory()->conversation()->running()->create();
        $step = AiRunStep::factory()->succeeded('0.002000')->create([
            'ai_run_id' => $run->id,
            'reserved_cost_usd' => '0.100000',
            'cache_read_input_tokens' => 3,
            'reasoning_tokens' => 2,
        ]);
        $call = AiToolCall::factory()->inStep($step)->create();
        AiRunStep::factory()->insideToolCall($call, AiRunStepSendKind::CreateFile)->succeeded('0.030000')->create([
            'reserved_cost_usd' => '0.200000',
            'cache_write_input_tokens' => 4,
        ]);
        AiRunStep::factory()->failed()->withUsage('0.000500')->create(['ai_run_id' => $run->id]);
        AiRunStep::factory()->failed()->create(['ai_run_id' => $run->id, 'reserved_cost_usd' => '0.400000']);
        AiRunStep::factory()->create(['ai_run_id' => $run->id, 'reserved_cost_usd' => '0.800000']);
        AiRunStep::factory()->succeeded('9.000000')->create();

        $this->assertSums($run, '1.510000', '0.032500', [
            'input_tokens' => 30,
            'output_tokens' => 15,
            'cache_read_input_tokens' => 3,
            'cache_write_input_tokens' => 4,
            'reasoning_tokens' => 2,
            'code_execution_seconds' => 5.0,
        ]);
    }

    public function test_a_run_without_steps_sums_to_zero(): void
    {
        $run = AiRun::factory()->conversation()->running()->create();

        $this->assertSums($run, '0.000000', '0.000000', self::ZERO_USAGE);
    }

    public function test_a_run_without_known_steps_has_zero_costs_and_usage(): void
    {
        $run = AiRun::factory()->conversation()->running()->create();
        AiRunStep::factory()->create(['ai_run_id' => $run->id]);
        AiRunStep::factory()->stopped()->create(['ai_run_id' => $run->id]);

        $this->assertSums($run, '0.020000', '0.000000', self::ZERO_USAGE);
    }

    /** 会話の型でない実行は和の列を持てない(作りの誤り) */
    public function test_a_run_of_another_type_is_a_logic_error(): void
    {
        $run = AiRun::factory()->running()->create();

        $this->expectException(LogicException::class);

        app(SumRunSteps::class)->columns($run);
    }

    /**
     * 返した和(使用量はトークンが整数、コード実行の時間が小数)。
     *
     * @param  array<string, int|float>  $usage
     */
    private function assertSums(AiRun $run, string $reservedUsd, string $costUsd, array $usage): void
    {
        $sums = app(SumRunSteps::class)->columns($run);

        $this->assertSame([$reservedUsd, $costUsd], [$sums['step_reserved_sum_usd'], $sums['step_cost_sum_usd']]);
        $this->assertSame($usage, $sums['step_usage_sum']);
    }
}

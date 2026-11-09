<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Database\Factories\Ai\AiRunStepFactory;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの状態ごとの列の有無(docs の 設計/AI/実行の記録.md 2.3)。状態ごとに通る行と断られる行を通す。
 */
final class ConversationStepStatusTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use BuildsConversationSteps;

    private const ERROR_CODE = 'ai_run_steps_status_error_code_check';

    private const FINISHED_AT = 'ai_run_steps_status_finished_at_check';

    private const USAGE = 'ai_run_steps_status_usage_check';

    private const CODE_EXECUTION = 'ai_run_steps_status_code_execution_seconds_check';

    public function test_a_running_step_has_no_error_no_end_no_usage_and_no_code_execution(): void
    {
        $this->assertSame(AiRunStepStatus::Running, $this->step()->refresh()->status);
        $this->assertRejectedBy(self::ERROR_CODE, fn () => $this->step(['error_code' => AiRunErrorCode::Provider]));
        $this->assertRejectedBy(self::FINISHED_AT, fn () => $this->step(['finished_at' => now()]));
        $this->assertRejectedBy(self::USAGE, fn () => AiRunStep::factory()->withUsage()->create());
        $inside = $this->insideToolCall();
        $this->assertRejectedBy(self::CODE_EXECUTION, fn () => $inside->create(['code_execution_seconds' => '1']));
    }

    public function test_a_succeeded_step_has_an_end_and_usage_and_code_execution_only_for_creating_a_file(): void
    {
        $this->assertNull(AiRunStep::factory()->succeeded()->create()->refresh()->code_execution_seconds);
        $insideSucceeded = $this->insideToolCall()->succeeded()->createOne()->refresh();
        $this->assertSame('5.000', $insideSucceeded->code_execution_seconds);

        $withoutUsage = ['status' => AiRunStepStatus::Succeeded, 'finished_at' => now()];
        $this->assertRejectedBy(self::USAGE, fn () => $this->step($withoutUsage));
        $succeeded = AiRunStep::factory()->succeeded();
        $provider = ['error_code' => AiRunErrorCode::Provider];
        $this->assertRejectedBy(self::ERROR_CODE, fn () => $succeeded->create($provider));
        $this->assertRejectedBy(self::FINISHED_AT, fn () => $succeeded->create(['finished_at' => null]));
        $this->assertRejectedBy(self::CODE_EXECUTION, fn () => $succeeded->create(['code_execution_seconds' => '1']));
        $inside = $this->insideToolCall()->succeeded();
        $this->assertRejectedBy(self::CODE_EXECUTION, fn () => $inside->create(['code_execution_seconds' => null]));
    }

    public function test_a_failed_step_has_an_error_and_an_end_and_usage_either_way(): void
    {
        $failed = AiRunStep::factory()->failed();
        $this->assertNull($failed->create()->refresh()->cost_usd);
        $this->assertSame('0.001000', $failed->withUsage()->create()->refresh()->cost_usd);
        $this->assertNull($this->insideToolCall()->failed()->createOne()->refresh()->code_execution_seconds);
        $insideWithUsage = $this->insideToolCall()->failed()->withUsage();
        $this->assertSame('5.000', $insideWithUsage->createOne()->refresh()->code_execution_seconds);

        $this->assertRejectedBy(self::ERROR_CODE, fn () => $failed->create(['error_code' => null]));
        $this->assertRejectedBy(self::FINISHED_AT, fn () => $failed->create(['finished_at' => null]));
        $noSeconds = ['code_execution_seconds' => null];
        $this->assertRejectedBy(self::CODE_EXECUTION, fn () => $insideWithUsage->create($noSeconds));
        $insideWithoutUsage = $this->insideToolCall()->failed();
        $seconds = ['code_execution_seconds' => '1'];
        $this->assertRejectedBy(self::CODE_EXECUTION, fn () => $insideWithoutUsage->create($seconds));
    }

    public function test_a_stopped_step_has_an_end_and_no_error_and_usage_either_way(): void
    {
        $stopped = AiRunStep::factory()->stopped();
        $this->assertNull($stopped->create()->refresh()->input_tokens);
        $this->assertSame(10, $stopped->withUsage()->create()->refresh()->input_tokens);
        $insideWithUsage = $this->insideToolCall()->stopped()->withUsage()->createOne()->refresh();
        $this->assertSame('5.000', $insideWithUsage->code_execution_seconds);

        $this->assertRejectedBy(self::ERROR_CODE, fn () => $stopped->create(['error_code' => AiRunErrorCode::Timeout]));
        $this->assertRejectedBy(self::FINISHED_AT, fn () => $stopped->create(['finished_at' => null]));
        $inside = $this->insideToolCall()->stopped();
        $this->assertRejectedBy(self::CODE_EXECUTION, fn () => $inside->create(['code_execution_seconds' => '1']));
    }

    /** ファイルを作る道具の中の呼び出しのステップ(処理中) */
    private function insideToolCall(): AiRunStepFactory
    {
        return AiRunStep::factory()->insideToolCall(AiToolCall::factory()->create());
    }
}

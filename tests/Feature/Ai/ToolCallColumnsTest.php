<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiToolCallDecision;
use App\Models\Ai\AiToolCall;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 道具の行の道具の名前と呼び出しの id の列(docs の 設計/AI/実行の記録.md 2.4): 道具の名前は長さの上限を置かず空も残す(65 字と空の名前の包みの結果)。
 * 呼び出しの id は長さの上限を置かず、一意は長さによらず効く(索引の 1 行の上限を超える長さの id が書け、同じ実行の同じ id は断る)。
 * 包みの中の例外の包みの結果が書ける(ほかの判定と状態の組の断り = ConversationToolCallStatesTest)。
 */
final class ToolCallColumnsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use BuildsConversationSteps;

    public function test_a_long_or_empty_tool_name_is_kept_as_a_wrapper_error(): void
    {
        $unknown = AiToolCall::factory()->wrapperError(AiToolCallDecision::UnknownTool);

        foreach ([str_repeat('a', 65), ''] as $name) {
            $call = $unknown->create(['tool_name' => $name]);

            $this->assertSame($name, $call->refresh()->tool_name);
        }
    }

    public function test_a_call_id_longer_than_an_index_row_is_written_and_stays_unique_in_a_run(): void
    {
        $step = $this->step();
        $long = str_repeat('id-', 2000);

        $call = AiToolCall::factory()->inStep($step)->create(['call_id' => $long]);

        $this->assertSame($long, $call->refresh()->call_id);
        $this->assertRejectedBy(
            'ai_tool_calls_ai_run_id_call_id_unique',
            fn () => AiToolCall::factory()->inStep($step)->create(['call_id' => $long]),
        );
        $this->assertSame($long, AiToolCall::factory()->create(['call_id' => $long])->call_id);
    }

    public function test_a_wrapper_exception_is_a_wrapper_error(): void
    {
        $call = AiToolCall::factory()->wrapperError(AiToolCallDecision::WrapperException)->create();

        $this->assertSame(AiToolCallDecision::WrapperException, $call->refresh()->decision);
    }
}

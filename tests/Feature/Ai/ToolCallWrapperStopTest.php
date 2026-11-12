<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\PriorToolCall;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiToolCall;
use Tests\Support\TransactionalTestCase;

/**
 * 道具を呼ぶ包みの止める印と時間の上限(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の 2・9.5 の表・設計/AI/実行の記録.md 2.4 の T1)、
 * 会話が無いとき(9.13。残りを動かさない)、形の外の検証が受ける同じステップの前の呼び出しの並び(設計/AI/道具と権限/README.md 10.1)。
 */
final class ToolCallWrapperStopTest extends TransactionalTestCase
{
    use RunsToolCalls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeWrapper();
    }

    /** 止める印は道具を探した後に見て、残りの全部に取り消しの結果を付け、中断の印(道具の途中の形)を 1 つ書く */
    public function test_the_stop_mark_cancels_the_rest_with_one_interruption(): void
    {
        $checks = 0;
        $stopped = function () use (&$checks): ?AiRunStopReason {
            return ++$checks >= 2 ? AiRunStopReason::User : null;
        };

        $outcome = $this->wrap([
            self::toolCall('c1', 'ai_read', ['module' => 'm_view']),
            self::toolCall('c2', 'ai_missing'),
            self::toolCall('c3', 'ai_read', ['module' => 'm_view']),
            self::toolCall('c4', 'ai_missing'),
        ], $stopped);

        $this->assertSame(AiToolCallStatus::Done, $this->row('c1')->status);
        $this->assertSame(AiToolCallDecision::UnknownTool, $this->row('c2')->decision);

        foreach (['c3', 'c4'] as $callId) {
            $row = $this->row($callId);
            $this->assertSame([null, AiToolCallStatus::Cancelled], [$row->decision, $row->status]);
            $this->assertNotNull($this->row($callId)->finished_at);
        }

        $contents = $this->storedContents();
        $this->assertSame([
            ['call_id' => 'c3', 'template' => 'user_cancelled', 'values' => [], 'body' => null, 'is_error' => true],
            ['call_id' => 'c4', 'template' => 'user_cancelled', 'values' => [], 'body' => null, 'is_error' => true],
            ['during_tool' => true],
        ], array_slice($contents, 2));
        $stop = [$outcome->stopReason, $outcome->interruption?->content];
        $this->assertSame([AiRunStopReason::User, ['during_tool' => true]], $stop);
        $this->assertSame(1, $this->tool('ai_read')->executions);
    }

    /** 時間の上限は残りの全部に上限で止めた旨の結果を付け、中断の印を書かない */
    public function test_the_time_limit_stops_the_rest_without_an_interruption(): void
    {
        $outcome = $this->wrap([
            self::toolCall('c1', 'ai_read', ['module' => 'm_view']),
            self::toolCall('c2', 'ai_create', ['module' => 'm_create']),
        ], fn (): AiRunStopReason => AiRunStopReason::TimeLimit);

        $this->assertSame([AiToolCallStatus::Cancelled, AiToolCallStatus::Cancelled], [
            $this->row('c1')->status,
            $this->row('c2')->status,
        ]);
        $templates = array_column($this->storedContents(), 'template');
        $this->assertSame(['stopped_by_time_limit', 'stopped_by_time_limit'], $templates);
        $this->assertSame([AiRunStopReason::TimeLimit, null], [$outcome->stopReason, $outcome->interruption]);
    }

    /** 会話の保存の足すが無しを返したら、残りの呼び出しを動かさずに返す */
    public function test_a_missing_conversation_leaves_the_rest_untouched(): void
    {
        $this->store->deleteConversation($this->conversationId);

        $outcome = $this->wrap([
            self::toolCall('c1', 'ai_missing'),
            self::toolCall('c2', 'ai_read', ['module' => 'm_view']),
        ]);

        $this->assertTrue($outcome->conversationMissing);
        $this->assertSame([], $outcome->results);
        $this->assertSame(['c1'], AiToolCall::query()->where('ai_run_id', $this->run->id)->pluck('call_id')->all());
        $this->assertSame(0, $this->tool('ai_read')->executions);
    }

    /**
     * 形の外の検証は、同じステップのこの呼び出しより前の呼び出しの並び(呼び出しの順。id・モデルが返した名前・形を通った引数か null・行の判定)を受ける。
     * 先頭の呼び出しは空の並び
     */
    public function test_the_validation_gets_the_earlier_calls_of_the_step(): void
    {
        $this->wrap([
            self::toolCall('c1', 'ai_read', ['module' => 'm_view']),
            self::toolCall('c2', 'ai_missing', ['module' => 'm_view']),
            self::toolCall('c3', 'ai_read', ['id' => 1]),
            self::toolCall('c4', 'ai_read', ['module' => 'm_out']),
            self::toolCall('c5', 'ai_read', ['module' => 'm_view', 'id' => 2]),
        ]);

        $this->assertEquals([[], [
            new PriorToolCall('c1', 'ai_read', ['module' => 'm_view'], AiToolCallDecision::Allow),
            new PriorToolCall('c2', 'ai_missing', null, AiToolCallDecision::UnknownTool),
            new PriorToolCall('c3', 'ai_read', null, AiToolCallDecision::InvalidInput),
            new PriorToolCall('c4', 'ai_read', ['module' => 'm_out'], AiToolCallDecision::Deny),
        ]], [$this->tool('ai_read')->validatedWith[0], end($this->tool('ai_read')->validatedWith)]);
    }
}

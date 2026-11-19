<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\RunClock;
use App\Actions\Ai\Conversation\RunNotifier;
use App\Actions\Ai\Conversation\RunProgress;
use App\Actions\Ai\Conversation\RunTools;
use App\Actions\Ai\Conversation\RunToolStep;
use App\Actions\Ai\Conversation\StepRecorder;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\ToolLookupAnswer;
use App\Actions\Ai\Core\ToolRunAnswer;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Actions\Ai\ToolCalls\ToolCallStages;
use App\Actions\Ai\ToolCalls\ToolCallWrites;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiToolCallText;
use App\Queries\Ai\RunChainCalls;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\Feature\Ai\RunsToolCalls;
use Tests\Support\TransactionalTestCase;

/**
 * 道具を動かす口と、中核が受け手を通らずに付けた結果の道具の行(docs の 設計/AI/会話の中核の口と受け手.md 13.3、
 * 設計/AI/実行の記録.md 2.4 の T1・T2・T4)。
 */
final class RunToolsTest extends TransactionalTestCase
{
    use RunsToolCalls;

    private RunToolStep $cursor;

    private RunTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeWrapper();
        $this->cursor = new RunToolStep($this->run, $this->granted, $this->store);
        $this->tools = new RunTools($this->cursor, app(ToolCallStages::class), app(ToolCallWrites::class));
        $this->notifier()->notify(new BeforeSendNotice($this->step->id, SendKind::Turn, 1, null, 10));
    }

    /** 無い道具は探すの時に包みが行と結果を書き、呼び出しの順はステップの中で数える */
    public function test_found_and_missing_tools_are_numbered_in_the_step(): void
    {
        $missing = $this->tools->find(new CoreToolCall('c1', 'no_such_tool', []), false);
        $this->assertSame(ToolLookupAnswer::Missing, $missing->answer);
        $this->assertSame(AiInstructionTemplate::UnknownTool->value, $missing->missingResult?->content['template']);

        $call = new CoreToolCall('c2', 'ai_read', ['module' => 'm_view']);
        $this->assertSame(ToolLookupAnswer::Found, $this->tools->find($call, false)->answer);
        $run = $this->tools->run($call, false);

        $this->assertSame(ToolRunAnswer::Result, $run->answer);
        $first = $this->row('c1');
        $this->assertSame([1, AiToolCallDecision::UnknownTool], [$first->position, $first->decision]);
        $this->assertSame([2, AiToolCallStatus::Done], [$this->row('c2')->position, $this->row('c2')->status]);
        $this->assertCount(2, $this->store->read($this->run->id) ?? []);
    }

    public function test_a_gone_conversation_is_reported(): void
    {
        $this->store->deleteConversation($this->conversationId);
        $call = new CoreToolCall('c1', 'ai_read', ['module' => 'm_view']);
        $this->tools->find($call, false);

        $this->assertSame(ToolRunAnswer::NoConversation, $this->tools->run($call, false)->answer);
    }

    /** 中核が付けた結果は、確定したモデルの文の名前と引数で取り消しの行を作り、後ろの中断の印と一緒に足す */
    public function test_results_the_core_attached_get_cancelled_rows(): void
    {
        $notifier = $this->notifier();
        $notifier->notify(new AppendNotice([ConversationItem::modelText('r1', [
            ConversationItem::toolCallBlock('c1', 'ai_read', ['module' => 'm_view']),
            ConversationItem::toolCallBlock('c2', 'ai_read', []),
        ])]));

        $notifier->notify(new AppendNotice([
            ConversationItem::toolResult('c1', AiInstructionTemplate::UserCancelled, [], null, true),
            ConversationItem::toolResult('c2', AiInstructionTemplate::UserCancelled, [], null, true),
            ConversationItem::interruption(true),
        ]));

        $this->assertSame([AiToolCallStatus::Cancelled, 1], [$this->row('c1')->status, $this->row('c1')->position]);
        $arguments = AiToolCallText::query()->findOrFail($this->row('c1')->id)->arguments;
        $this->assertSame('m_view', $arguments['module'] ?? null);
        $this->assertSame([AiToolCallStatus::Cancelled, 2], [$this->row('c2')->status, $this->row('c2')->position]);
        $this->assertCount(4, $this->store->read($this->run->id) ?? []);
    }

    public function test_results_must_lead_the_notice(): void
    {
        $notifier = $this->notifier();
        $notifier->notify(new AppendNotice([ConversationItem::modelText('r1', [
            ConversationItem::toolCallBlock('c1', 'ai_read', []),
        ])]));

        $this->expectException(LogicException::class);

        $notifier->notify(new AppendNotice([
            ConversationItem::interruption(true),
            ConversationItem::toolResult('c1', AiInstructionTemplate::UserCancelled, [], null, true),
        ]));
    }

    /** 先に動かす呼び出しは答えの実行の承認した件だけ(ほかの実行では作りの誤り) */
    public function test_a_pre_run_call_outside_an_answer_run_is_a_construction_error(): void
    {
        $this->expectException(LogicException::class);

        $this->tools->find(new CoreToolCall('c1', 'ai_read', []), true);
    }

    private function notifier(): RunNotifier
    {
        $progress = new RunProgress($this->run->id, 60, new RunClock(CarbonImmutable::now(), 60));

        return new RunNotifier(
            $this->run->id,
            $this->store,
            new RunChainCalls,
            app(StepRecorder::class),
            $progress,
            $this->cursor,
            app(ToolCallRows::class),
        );
    }
}

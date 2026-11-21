<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\RunClock;
use App\Actions\Ai\Conversation\RunNotifier;
use App\Actions\Ai\Conversation\RunProgress;
use App\Actions\Ai\Conversation\RunToolChildren;
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
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCallText;
use App\Queries\Ai\RunChainCalls;
use Carbon\CarbonImmutable;
use DateTimeZone;
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
        $stages = app(ToolCallStages::class);
        $this->tools = new RunTools($this->cursor, $stages, app(ToolCallWrites::class), app(RunToolChildren::class));
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

    /**
     * 返り値の道具の中の最後の送信の完了 = その呼び出しの中で成功したステップの行の終わりの最大(失敗の終わり・ほかの呼び出しは数えない。
     * 中の送信が無ければ空 = docs の 設計/AI/会話の中核.md 13.2)。
     */
    public function test_the_run_returns_the_last_completion_inside_the_call(): void
    {
        $call = new CoreToolCall('c2', 'ai_read', ['module' => 'm_view']);
        $this->tools->find($call, false);
        $this->assertNull($this->tools->run($call, false)->lastSendFinishedAt);

        $inside = AiRunStep::factory()->insideToolCall($this->row('c2'));
        $at = static fn (string $time): array => [
            'started_at' => CarbonImmutable::parse("2026-11-18 {$time}+00")->subMinute(),
            'finished_at' => CarbonImmutable::parse("2026-11-18 {$time}+00"),
        ];
        $inside->succeeded()->create($at('01:00:00'));
        $inside->succeeded()->create($at('02:00:00'));
        $inside->failed()->create($at('03:00:00'));

        $finished = $this->cursor->lastSendFinishedAt('c2');
        $utc = $finished?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
        $this->assertSame('2026-11-18T02:00:00+00:00', $utc);
        $this->assertNull($this->cursor->lastSendFinishedAt('c9'));
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

<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\RunClock;
use App\Actions\Ai\Conversation\RunNotifier;
use App\Actions\Ai\Conversation\RunProgress;
use App\Actions\Ai\Conversation\RunToolStep;
use App\Actions\Ai\Conversation\StepRecorder;
use App\Actions\Ai\Core\AfterSendNotice;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\TextDeltaNotice;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiRunText;
use App\Models\Ai\AiToolCall;
use App\Queries\Ai\RunChainCalls;
use Carbon\CarbonImmutable;
use Tests\Feature\Ai\FakeConversationStore;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Support\TransactionalTestCase;

/**
 * 知らせの口の書き先(docs の 設計/AI/会話の中核の口と受け手.md 13.3)と、実行の鎖をまたぐ呼び出しの id の重なり(設計/AI/実行の記録.md 2.4)。
 */
final class RunNotifierTest extends TransactionalTestCase
{
    private FakeConversationStore $store;

    private AiRun $previous;

    private AiRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        FakeConversationStore::createTables();
        $this->store = new FakeConversationStore;
        $kind = ['kind' => RecordingConversationKind::CODE];
        $this->previous = AiRun::factory()->conversation()->failed()->create($kind);
        $this->run = AiRun::factory()->conversation()->running()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $this->previous->id,
        ]);
        $conversation = $this->store->startConversation($this->previous->id);
        $this->store->continueConversation($conversation, $this->run->id);
    }

    public function test_items_go_to_the_conversation(): void
    {
        $item = ConversationItem::modelText('r1', [ConversationItem::textBlock('答え')]);

        $reply = $this->notifier()->notify(new AppendNotice([$item]));

        $this->assertFalse($reply->conversationGone);
        $this->assertCount(1, $this->store->read($this->run->id) ?? []);
    }

    /** 要約の境目を書いたら、その実行で要約したことを書く。ほかの項目では書かない */
    public function test_a_compact_boundary_marks_the_run_compacted(): void
    {
        $notifier = $this->notifier();
        $notifier->notify(new AppendNotice([ConversationItem::userText('問い')]));
        $this->assertNull(AiRun::query()->findOrFail($this->run->id)->compacted);

        $notifier->notify(new AppendNotice([
            ConversationItem::compactBoundary(10, '2026-11-19'),
            ConversationItem::compactSummary('要約'),
        ]));

        $this->assertTrue(AiRun::query()->findOrFail($this->run->id)->compacted);
    }

    public function test_a_gone_conversation_is_reported(): void
    {
        $alone = AiRun::factory()->conversation()->running()->create(['kind' => RecordingConversationKind::CODE]);

        $reply = $this->notifier($alone)->notify(new AppendNotice([ConversationItem::userText('問い')]));

        $this->assertTrue($reply->conversationGone);
    }

    /** 項目は書いてから、前の実行の道具の行と同じ呼び出しの id を重なりとして返す。鎖の外の実行の行とは重ならない */
    public function test_call_ids_overlap_across_the_run_chain(): void
    {
        $step = AiRunStep::factory()->create(['ai_run_id' => $this->previous->id]);
        AiToolCall::factory()->inStep($step)->create(['call_id' => 'c1']);
        $other = AiRunStep::factory()->create();
        AiToolCall::factory()->inStep($other)->create(['call_id' => 'c2']);
        $item = ConversationItem::modelText('r1', [
            ConversationItem::toolCallBlock('c1', 'search_records', []),
            ConversationItem::toolCallBlock('c2', 'search_records', []),
        ]);

        $reply = $this->notifier()->notify(new AppendNotice([$item]));

        $this->assertSame(['c1'], $reply->duplicateCallIds);
        $this->assertCount(1, $this->store->read($this->run->id) ?? []);
    }

    /** 応答が終わりまで届いたら、書く間隔を待たずに途中の文を書く */
    public function test_the_end_of_a_response_writes_the_partial_text(): void
    {
        $step = AiRunStep::factory()->create(['ai_run_id' => $this->run->id]);
        $notifier = $this->notifier();
        $notifier->notify(new TextDeltaNotice($step->id, 'a'));
        $notifier->notify(new TextDeltaNotice($step->id, 'b'));

        $usage = [
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ];
        $notifier->notify(new AfterSendNotice($step->id, $usage, ResponseFinish::Stop, null, false));

        $this->assertSame('ab', AiRunText::query()->findOrFail($this->run->id)->partial_text[0]['text'] ?? null);
    }

    private function notifier(?AiRun $run = null): RunNotifier
    {
        $run ??= $this->run;
        $progress = new RunProgress($run->id, 60, new RunClock(CarbonImmutable::now(), 60));

        $cursor = new RunToolStep($run, new GrantedTools([], []), $this->store);

        return new RunNotifier(
            $run->id,
            $this->store,
            new RunChainCalls,
            app(StepRecorder::class),
            $progress,
            $cursor,
            app(ToolCallRows::class),
        );
    }
}

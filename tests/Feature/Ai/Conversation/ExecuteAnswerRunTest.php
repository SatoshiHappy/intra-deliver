<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\AnswerNotConfirmed;
use App\Actions\Ai\Conversation\ExecuteConversation;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopOutcome;
use App\Actions\Ai\Core\LoopStop;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use Tests\Feature\Ai\Conversation\Support\ScriptedCore;
use Tests\Feature\Ai\FakeConversationStore;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 会話のジョブの答えの実行(docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの 4 と 9.3、確認待ちの行の終わり方 =
 * 設計/AI/実行の記録.md 2.4 の T6〜T8)。中核は決めた結果を返す物に差し替える(先に動かす呼び出しの書き方は AnswerRunsTest が通す)。
 */
final class ExecuteAnswerRunTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private FakeConversationStore $store;

    private int $conversationId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        FakeConversationStore::createTables();
        $this->store = new FakeConversationStore;
    }

    /** 「実行」は承認した件を先に動かす呼び出しで中核に渡し、時間の上限で止めたら残った件を実行の答えのまま取り消す(T7) */
    public function test_an_approve_answer_passes_the_calls_and_a_time_limit_stop_cancels_the_rest(): void
    {
        $core = $this->core(LoopEnding::stopped(LoopStop::TimeLimit));
        [$run, $row] = $this->answerRun(AiConversationAnswer::Approve);

        app(ExecuteConversation::class)->execute($run->id);

        $calls = $core->loops[0]->preRunCalls ?? [];
        $this->assertSame(['c1'], array_map(fn (CoreToolCall $call): string => $call->callId, $calls));
        $this->assertSame(AiRunStatus::Stopped, $run->refresh()->status);
        $this->assertEnded($row, AiToolCallStatus::Cancelled, AiToolCallAnswer::Approve, $run);
    }

    /** 会話が無ければループを呼ばずに止めた(利用者)で、承認待ちを実行の答えのまま取り消す(T7 の会話が無い) */
    public function test_a_gone_conversation_cancels_the_approved_calls(): void
    {
        $core = $this->core(LoopEnding::succeeded());
        [$run, $row] = $this->answerRun(AiConversationAnswer::Approve);
        $this->store->deleteConversation($this->conversationId);

        app(ExecuteConversation::class)->execute($run->id);

        $this->assertSame([], $core->loops);
        $this->assertSame(AiRunStatus::Stopped, $run->refresh()->status);
        $this->assertEnded($row, AiToolCallStatus::Cancelled, AiToolCallAnswer::Approve, $run);
    }

    /** 「やめる」で会話が無ければ断りを書けずに止めた(利用者)で、確認待ちは断られた(答え = やめる)で終える(T8) */
    public function test_a_reject_answer_with_a_gone_conversation_still_rejects(): void
    {
        $this->core(LoopEnding::succeeded());
        [$run, $row] = $this->answerRun(AiConversationAnswer::Reject);
        $this->store->deleteConversation($this->conversationId);

        app(ExecuteConversation::class)->execute($run->id);

        $this->assertSame(AiRunStatus::Stopped, $run->refresh()->status);
        $this->assertEnded($row, AiToolCallStatus::Rejected, AiToolCallAnswer::Reject, $run);
    }

    /** 「やめる」はループを呼ばずに断りを書き、止めた(利用者)で終える(H2・T8) */
    public function test_a_reject_answer_stops_without_the_loop(): void
    {
        $core = $this->core(LoopEnding::succeeded());
        [$run, $row] = $this->answerRun(AiConversationAnswer::Reject);

        app(ExecuteConversation::class)->execute($run->id);

        $this->assertSame([], $core->loops);
        $this->assertSame(AiRunStatus::Stopped, $run->refresh()->status);
        $this->assertEnded($row, AiToolCallStatus::Rejected, AiToolCallAnswer::Reject, $run);
    }

    /** 始めの確かめが合わない(引数が違う)ときは失敗(確認)で、確認待ちの行は答えなしで取り消す(T6) */
    public function test_a_mismatch_fails_as_confirmation_and_cancels_the_awaiting_row(): void
    {
        $core = $this->core(LoopEnding::succeeded());
        [$run, $row] = $this->answerRun(AiConversationAnswer::Approve, ['title' => '違う']);

        $this->assertInstanceOf(AnswerNotConfirmed::class, $this->executeCatching($run->id));

        $this->assertSame([], $core->loops);
        $this->assertSame(AiRunErrorCode::Confirmation, $run->refresh()->error_code);
        $this->assertEnded($row, AiToolCallStatus::Cancelled, null, null);
    }

    private function assertEnded(AiToolCall $row, AiToolCallStatus $status, ?AiToolCallAnswer $answer, ?AiRun $by): void
    {
        $row->refresh();
        $this->assertSame([$status, $answer, $by?->id], [$row->status, $row->answer, $row->answer_run_id]);
    }

    /**
     * 確認待ちで終わった前の実行(作るの件 1 つ)と、それに答える答えの実行を作る。
     *
     * @param  array<string, mixed>  $inConversation  会話の保存のモデルの文の呼び出しの引数
     * @return array{AiRun, AiToolCall}
     */
    private function answerRun(AiConversationAnswer $answer, array $inConversation = ['title' => '1']): array
    {
        $previous = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);
        $row = AiToolCall::factory()
            ->inStep(AiRunStep::factory()->create(['ai_run_id' => $previous->id]))
            ->asked()
            ->create(['call_id' => 'c1', 'tool_name' => 'ai_create_record']);
        AiToolCallText::factory()->create([
            'ai_tool_call_id' => $row->id,
            'arguments' => ['title' => '1'],
            'result_text' => null,
            'confirmation' => ['target' => ['module' => 'm_tasks']],
            'written_arguments' => ['title' => '1'],
        ]);
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous->id,
            'answer' => $answer,
        ]);
        $this->conversationId = $this->store->startConversation($previous->id);
        $this->store->append($previous->id, [ConversationItem::modelText('r1', [
            ConversationItem::toolCallBlock('c1', 'ai_create_record', $inConversation),
        ])]);
        $this->store->continueConversation($this->conversationId, $run->id);

        return [$run, $row];
    }

    private function core(LoopEnding $ending): ScriptedCore
    {
        $core = new ScriptedCore(null, new LoopOutcome($ending, []));
        $this->app->instance(ConversationCore::class, $core);

        return $core;
    }

    private function executeCatching(int $runId): ?Throwable
    {
        try {
            app(ExecuteConversation::class)->execute($runId);
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }
}

<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\AnswerNotConfirmed;
use App\Actions\Ai\Conversation\ExecuteConversation;
use App\Actions\Ai\Conversation\RunNoLongerRunning;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopOutcome;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\SentResponse;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Ai\Conversation\Support\ScriptedCore;
use Tests\Feature\Ai\FakeConversationStore;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 会話のジョブの本体(順 = docs の 設計/AI/エージェントの実行/README.md 9.1、状態の移り方 = 設計/AI/エージェントの実行/状態の移り方.md 9.11 の
 * S2・S4・S22〜S24 と、ループの失敗の種類から失敗の種類への写し)。中核は決めた結果を返す物に差し替える(中核の行は中核の約束のテストが通す)。
 */
final class ExecuteConversationTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private FakeConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        FakeConversationStore::createTables();
        $this->store = new FakeConversationStore;
    }

    public function test_a_finished_loop_writes_the_ending_and_passes_the_title(): void
    {
        $core = $this->core(LoopEnding::succeeded());
        $run = $this->queuedRun(true);

        app(ExecuteConversation::class)->execute($run->id);

        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($run->id)->status);
        $this->assertCount(1, $core->sent);
        $this->assertCount(1, $core->loops);
        $this->assertSame(['題'], array_map(static fn ($ended) => $ended->title, $this->conversationKind->completed));
    }

    public function test_no_title_step_when_the_request_does_not_ask(): void
    {
        $core = $this->core(LoopEnding::succeeded());

        app(ExecuteConversation::class)->execute($this->queuedRun(false)->id);

        $this->assertSame([], $core->sent);
        $this->assertNull($this->conversationKind->completed[0]->title ?? null);
    }

    /** 会話が無ければループを呼ばずに止めた(利用者)(S4) */
    public function test_a_gone_conversation_stops_without_the_loop(): void
    {
        $core = $this->core(LoopEnding::succeeded());
        $run = AiRun::factory()->conversation()->create(['kind' => RecordingConversationKind::CODE]);

        app(ExecuteConversation::class)->execute($run->id);

        $this->assertSame(AiRunStatus::Stopped, AiRun::query()->findOrFail($run->id)->status);
        $this->assertSame([], $core->loops);
    }

    /**
     * @return array<string, array{LoopFailure, AiRunErrorCode}>
     */
    public static function failures(): array
    {
        return [
            '続きを使い切った' => [LoopFailure::ContinuationsExhausted, AiRunErrorCode::Incomplete],
            '断った' => [LoopFailure::Refused, AiRunErrorCode::Incomplete],
            '時間切れ' => [LoopFailure::Timeout, AiRunErrorCode::Timeout],
            '接続先' => [LoopFailure::Provider, AiRunErrorCode::Provider],
        ];
    }

    /** ループの失敗は失敗の種類に写し、題を失敗の受け手に渡して投げる */
    #[DataProvider('failures')]
    public function test_a_loop_failure_fails_the_run(LoopFailure $failure, AiRunErrorCode $code): void
    {
        $this->core(LoopEnding::failed($failure));
        $run = $this->queuedRun(true);

        $thrown = $this->executeCatching($run->id);

        $saved = AiRun::query()->findOrFail($run->id);
        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame([AiRunStatus::Failed, $code], [$saved->status, $saved->error_code]);
        $this->assertSame('題', $this->conversationKind->failed[0]->title ?? null);
    }

    /** 中核からの受け手の例外は失敗(完了の処理)にして投げ直す(S23。題は渡す) */
    public function test_an_exception_fails_the_run_as_completion(): void
    {
        $exception = new RuntimeException('受け手の誤り');
        $this->core(null, $exception);
        $run = $this->queuedRun(true);

        $this->assertSame($exception, $this->executeCatching($run->id));
        $this->assertSame(AiRunErrorCode::Completion, AiRun::query()->findOrFail($run->id)->error_code);
        $this->assertSame('題', $this->conversationKind->failed[0]->title ?? null);
    }

    /** ほかの経路が先に終えていたら何もしない(S24) */
    public function test_a_run_ended_during_the_loop_is_left_alone(): void
    {
        $this->core(null, new RunNoLongerRunning('終わっていた'));
        $run = $this->queuedRun(false);

        app(ExecuteConversation::class)->execute($run->id);

        $this->assertSame(AiRunStatus::Running, AiRun::query()->findOrFail($run->id)->status);
        $this->assertSame([], $this->conversationKind->failed);
    }

    /** 待ちの間に終えた実行は変えない(S3 の後の S24) */
    public function test_a_finished_run_is_not_started(): void
    {
        $core = $this->core(LoopEnding::succeeded());
        $run = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);

        app(ExecuteConversation::class)->execute($run->id);

        $this->assertSame([], $core->loops);
    }

    /** 種類の実装が引けなければ失敗(完了の処理)(S2) */
    public function test_an_unregistered_kind_fails_as_completion(): void
    {
        $run = AiRun::factory()->conversation()->create(['kind' => 'missing.conversation']);

        $this->assertInstanceOf(LogicException::class, $this->executeCatching($run->id));
        $saved = AiRun::query()->findOrFail($run->id);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$saved->status, $saved->error_code]);
    }

    /** 答えの実行は書く道具の承認と一緒に扱うので、今は作りの誤り(失敗(完了の処理)) */
    /** 答えの実行の始めの確かめが合わない(前の実行に確認待ちの呼び出しが無い)ときは、ループを呼ばずに失敗(確認)で終える(9.3) */
    public function test_an_answer_run_without_awaiting_calls_fails_as_confirmation(): void
    {
        $core = $this->core(LoopEnding::succeeded());
        $previous = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous->id,
            'answer' => AiConversationAnswer::Approve,
        ]);
        $this->store->continueConversation($this->store->startConversation($previous->id), $run->id);

        $this->assertInstanceOf(AnswerNotConfirmed::class, $this->executeCatching($run->id));
        $this->assertSame(AiRunErrorCode::Confirmation, AiRun::query()->findOrFail($run->id)->error_code);
        $this->assertSame([], $core->loops);
    }

    private function core(?LoopEnding $ending, ?Throwable $exception = null): ScriptedCore
    {
        $title = new SentResponse([], ResponseFinish::Stop, [
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ], ['title' => '題']);
        $core = new ScriptedCore($title, $ending === null ? null : new LoopOutcome($ending, []), $exception);
        $this->app->instance(ConversationCore::class, $core);

        return $core;
    }

    private function queuedRun(bool $wantsTitle): AiRun
    {
        $previous = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous->id,
            'wants_title' => $wantsTitle,
        ]);
        $conversation = $this->store->startConversation($previous->id);
        $this->store->continueConversation($conversation, $run->id);
        $this->store->append($run->id, [ConversationItem::userText('会議の要約を作って')]);

        return $run;
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

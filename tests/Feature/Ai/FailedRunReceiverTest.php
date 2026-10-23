<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\PromptAgent;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Records\Record;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 種類の失敗の受け手(docs の 設計/AI/公開の口.md 3.1、データと失敗と検査.md 7 節・8 節): 実行を失敗にした書き込みの後に別のトランザクションで
 * 1 回だけ呼ばれ、失敗の種類と細かい理由が渡る。422 で断られた依頼では呼ばれない。受け手の例外は報告され、元の例外が投げられ、状態は失敗のまま。
 */
final class FailedRunReceiverTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestPromptKinds;

    private const MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        config(['ai.providers.anthropic.key' => 'fake-key-for-tests']);
    }

    public function test_is_called_once_after_the_failure_is_written_in_its_own_transaction(): void
    {
        $error = ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => '誤り']];
        Http::fake([self::MESSAGES_URL => Http::response($error, 500)]);
        $runId = $this->queuedRun();
        $level = DB::transactionLevel();

        $this->executeFailing($runId);
        (new RunPromptJob($runId, 100))->failed(new RuntimeException('ジョブの失敗'));

        $expected = new FailedRun($runId, RecordingPromptKind::CODE, null, $this->requesterUserId(), 'provider', null);
        $this->assertEquals([$expected], $this->recordingKind->failed);
        $this->assertSame([['failed', $level + 1]], $this->recordingKind->failedSeen);
    }

    public function test_a_timeout_has_no_reason(): void
    {
        $timedOut = 'cURL error 28: Operation timed out after 100001 milliseconds';
        Http::fake([self::MESSAGES_URL => Http::failedConnection($timedOut)]);

        $this->executeFailing($this->queuedRun());

        $this->assertSame([['timeout', null]], $this->errorCodesAndReasons());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function incompleteResponses(): array
    {
        return [
            '出力が上限で切れた' => ['max_tokens', FailedRun::OUTPUT_TRUNCATED],
            '接続先が応答を断った' => ['refusal', FailedRun::CONTENT_REFUSED],
        ];
    }

    #[DataProvider('incompleteResponses')]
    public function test_an_incomplete_response_has_the_reason_of_its_finish(string $stopReason, string $reason): void
    {
        Http::fake([self::MESSAGES_URL => Http::response([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => '{"summary":"途中']],
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 3],
        ])]);

        $this->executeFailing($this->queuedRun());

        $this->assertSame([['incomplete', $reason]], $this->errorCodesAndReasons());
    }

    /**
     * @return array<string, array{Closure(): Throwable, string|null}>
     */
    public static function completionExceptions(): array
    {
        return [
            '権限が無い(403)' => [fn () => new AuthorizationException('更新を許されない'), FailedRun::RECORD_FORBIDDEN],
            'レコードが無い(404)' => [fn () => new ModelNotFoundException('レコードが無い'), FailedRun::RECORD_NOT_FOUND],
            '更新が断られた(422)' => [
                fn () => ValidationException::withMessages(['record' => '申請中']),
                FailedRun::RECORD_REJECTED,
            ],
            '2 段目の依頼が上限で断られた(422)' => [
                fn () => new RunRequestDeclined(RunRequestDeclined::BUDGET, '上限'),
                RunRequestDeclined::BUDGET,
            ],
            '2 段目の依頼が長すぎて断られた(422)' => [
                fn () => new RunRequestDeclined(RunRequestDeclined::TOO_LONG, '長すぎる'),
                RunRequestDeclined::TOO_LONG,
            ],
            '受け手が決めた理由' => [fn () => new RunCompletionFailed('segments_empty', '区間が空'), 'segments_empty'],
            'ほかの例外' => [fn () => new RuntimeException('出力を解釈できない'), null],
        ];
    }

    /**
     * @param  Closure(): Throwable  $exception
     */
    #[DataProvider('completionExceptions')]
    public function test_a_completion_failure_has_the_reason_of_its_exception(Closure $exception, ?string $reason): void
    {
        $this->recordingKind->onComplete = fn () => throw $exception();
        PromptAgent::fake([['summary' => '要約']]);

        $this->executeFailing($this->queuedRun(), Throwable::class);

        $this->assertSame([['completion', $reason]], $this->errorCodesAndReasons());
    }

    public function test_a_worker_lost_run_passes_the_current_record_which_is_null_after_the_deletion(): void
    {
        $module = $this->recordModule('ai_notes');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $runId = $this->queuedRun(recordId: $record->id);
        Record::query()->whereKey($record->id)->delete();

        (new RunPromptJob($runId, 100))->failed(new RuntimeException('worker が落ちた'));

        $requester = $this->requesterUserId();
        $expected = new FailedRun($runId, RecordingPromptKind::CODE, null, $requester, 'worker_lost', null);
        $this->assertEquals([$expected], $this->recordingKind->failed);
    }

    public function test_a_kind_without_an_implementation_cannot_be_called(): void
    {
        $runId = $this->queuedRun();
        AiRun::query()->whereKey($runId)->update(['kind' => self::UNREGISTERED_KIND]);

        $this->executeFailing($runId, LogicException::class);

        $this->assertSame([], $this->recordingKind->failed);
    }

    public function test_is_not_called_for_a_request_declined_with_422(): void
    {
        $this->setKindSetting(RecordingPromptKind::CODE, 'max_input_chars', 10);

        $request = fn () => app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        $this->assertThrows($request, RunRequestDeclined::class);

        $this->assertSame([0, []], [AiRun::query()->count(), $this->recordingKind->failed]);
    }

    public function test_an_exception_of_the_receiver_is_reported_and_the_original_is_thrown(): void
    {
        Exceptions::fake();
        $this->recordingKind->onComplete = fn () => throw new RunCompletionFailed('output_invalid', '元の例外');
        $this->recordingKind->failedThrows = new RuntimeException('受け手の例外');
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();

        $this->assertThrows(fn () => app(ExecutePrompt::class)->execute($runId), RunCompletionFailed::class, '元の例外');

        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === '受け手の例外');
    }

    /**
     * @return list<array{string, string|null}> 受け手に渡った失敗の種類と細かい理由
     */
    private function errorCodesAndReasons(): array
    {
        return array_map(fn (FailedRun $run): array => [$run->errorCode, $run->reason], $this->recordingKind->failed);
    }
}

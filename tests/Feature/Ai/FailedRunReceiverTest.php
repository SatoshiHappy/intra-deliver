<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\FailRun;
use App\Actions\Ai\PromptAgent;
use App\Actions\Ai\RunResult;
use App\Actions\Notice\Api\NoticeMessage;
use App\Actions\Notice\Api\SendNotices;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
 * 種類の失敗の受け手(docs の 設計/AI/公開の口.md 3.1、実行と費用.md 4 節の「失敗の書き方」、データと失敗と検査.md 7 節・8 節)。
 * 鍵なしで読んだ時に終わっていれば呼ばないことは FinishedRunKeptTest、鍵の順は CompletionLockOrderTest。
 */
final class FailedRunReceiverTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestPromptKinds;

    private const MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    /** 受け手が書く通知の種類 */
    private const NOTICE_TYPE = 'test.failed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        config(['ai.providers.anthropic.key' => 'fake-key-for-tests']);
    }

    /**
     * 受け手は失敗を書く前(実行は処理中のまま)に、失敗を書くトランザクションの中で呼ばれ、ジョブの failed() と二重に呼んでも 1 回。
     */
    public function test_is_called_once_before_the_failure_is_written_in_the_same_transaction(): void
    {
        $error = ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => '誤り']];
        Http::fake([self::MESSAGES_URL => Http::response($error, 500)]);
        $runId = $this->queuedRun();
        $level = DB::transactionLevel();

        $this->executeFailing($runId);
        (new RunPromptJob($runId, 100))->failed(new RuntimeException('ジョブの失敗'));

        $expected = new FailedRun($runId, RecordingPromptKind::CODE, null, $this->requesterUserId(), 'provider', null);
        $this->assertEquals([$expected], $this->recordingKind->failed);
        $this->assertSame([['running', $level + 1]], $this->recordingKind->failedSeen);
    }

    /**
     * 受け手が書いた通知は、失敗の書き込みと同じトランザクションで確定する(受け手の通知 → 実行の行の失敗 → 1 回の確定の順)。
     */
    public function test_the_notice_of_the_receiver_commits_together_with_the_failure(): void
    {
        $runId = $this->queuedRun();
        $this->recordingKind->onFailed = fn (FailedRun $run) => $this->sendNotice($run);
        $base = DB::transactionLevel();
        $events = [];
        DB::listen(function (QueryExecuted $query) use (&$events): void {
            if (str_starts_with($query->sql, 'insert into "notices"')) {
                $events[] = 'notice';
            } elseif (str_starts_with($query->sql, 'update "ai_runs"')) {
                $events[] = 'failure';
            }
        });
        Event::listen(TransactionCommitted::class, function () use (&$events, $base): void {
            if (DB::transactionLevel() === $base) {
                $events[] = 'commit';
            }
        });

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));

        $this->assertSame(['notice', 'failure', 'commit'], $events);
        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
        $this->assertSame(1, Notice::query()->where('type', self::NOTICE_TYPE)->count());
    }

    /**
     * 受け手を呼んだ後の確かめ直し(実行の行の鍵の下)で終わっていたら、受け手の書き込みを巻き戻し、状態・使用量・費用・原文を変えない。
     * 同じ接続でほかの経路を真似るため、ほかの経路の書き込みは受け手の中に置き、受け手の書き込みと一緒に巻き戻る(呼ぶ前の値のまま残ることを確かめる)。
     */
    public function test_a_run_finished_after_the_receiver_was_called_is_kept_and_the_notice_is_rolled_back(): void
    {
        $runId = $this->queuedRun();
        $before = AiRun::query()->findOrFail($runId)->getAttributes();
        $this->recordingKind->onFailed = function (FailedRun $run): void {
            $this->sendNotice($run);
            AiRun::query()->whereKey($run->runId)->update([
                'status' => AiRunStatus::Failed,
                'error_code' => AiRunErrorCode::WorkerLost,
                'error_message' => 'worker が落ちた',
                'finished_at' => now(),
            ]);
        };
        $pricing = ['input' => 1.0, 'output' => 5.0];
        $usage = ['input_tokens' => 10, 'output_tokens' => 3, 'pricing' => $pricing, 'cost_usd' => '0.000025'];

        $result = new RunResult($usage, '{"summary":"途中');

        app(FailRun::class)->execute($runId, AiRunErrorCode::Incomplete, new RuntimeException('切れた'), $result);

        $this->assertCount(1, $this->recordingKind->failed);
        $this->assertSame($before, AiRun::query()->findOrFail($runId)->getAttributes());
        $this->assertNull(AiRunText::query()->findOrFail($runId)->output_text);
        $this->assertSame(0, Notice::query()->where('type', self::NOTICE_TYPE)->count());
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

    /**
     * 受け手が投げたら、受け手の書き込み(通知)は残らず、失敗だけが分かった分の使用量・費用・原文と一緒に残り、受け手の例外が報告され、元の例外が投げられる。
     */
    public function test_an_exception_of_the_receiver_is_reported_and_the_original_is_thrown(): void
    {
        Exceptions::fake();
        $this->recordingKind->onComplete = fn () => throw new RunCompletionFailed('output_invalid', '元の例外');
        $this->recordingKind->onFailed = fn (FailedRun $run) => $this->sendNotice($run);
        $this->recordingKind->failedThrows = new RuntimeException('受け手の例外');
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();

        $this->assertThrows(fn () => app(ExecutePrompt::class)->execute($runId), RunCompletionFailed::class, '元の例外');

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$run->status, $run->error_code]);
        $this->assertNotNull($run->input_tokens);
        $this->assertNotNull($run->cost_usd);
        $output = (string) AiRunText::query()->findOrFail($runId)->output_text;
        $this->assertSame(['summary' => '要約'], json_decode($output, true));
        $this->assertSame(0, Notice::query()->where('type', self::NOTICE_TYPE)->count());
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === '受け手の例外');
    }

    /** 受け手の通知(依頼者に 1 件) */
    private function sendNotice(FailedRun $run): void
    {
        $message = new NoticeMessage(self::NOTICE_TYPE, null, null, null, []);
        app(SendNotices::class)->execute($message, [$run->requesterUserId]);
    }

    /**
     * @return list<array{string, string|null}> 受け手に渡った失敗の種類と細かい理由
     */
    private function errorCodesAndReasons(): array
    {
        return array_map(fn (FailedRun $run): array => [$run->errorCode, $run->reason], $this->recordingKind->failed);
    }
}

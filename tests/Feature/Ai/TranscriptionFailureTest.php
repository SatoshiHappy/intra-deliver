<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunTranscriptionJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Upload\Upload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Transcription;
use RuntimeException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 文字起こしの実行の失敗(docs の 設計/AI/実行と費用.md 4 節・4.2・5.1、データと失敗と検査.md 7 節): 接続先の誤り = provider・時間切れ = timeout
 * (費用は失敗した区切りまでの秒で数え、トークンは分からない)、応答が区切りの終わりまで届かない = incomplete(output_truncated)、実体が無い = conversion
 * (費用 0)、受け手の例外 = completion(受け手の書き込みは残らない)、依頼者が無効 = completion(requester_inactive。受け手を呼ばない)、worker が落ちた =
 * worker_lost。どの失敗でも受け手の failed は 1 回だけ別のトランザクションで呼ばれ、一時ファイルは残らない。
 */
final class TranscriptionFailureTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use UsesTestTranscriptionKinds;

    /** 無音 5〜6 秒・11〜12 秒の 25 秒(C = 10・W = 2 → n = 4、区切り = 0〜5.5・5.5〜11.5・11.5〜18.75・18.75〜25 秒) */
    private const PAUSED_SPEECH = 'if(between(t,5,6)+between(t,11,12),0,sin(2*PI*440*t))';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestTranscriptionKind();
    }

    protected function tearDown(): void
    {
        $this->removeTranscriptionTemp();
        parent::tearDown();
    }

    public function test_a_provider_error_at_the_second_chunk_counts_the_called_chunks(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload(self::PAUSED_SPEECH, 25));
        $level = DB::transactionLevel();
        Transcription::fake([
            $this->transcriptionResponse([['一つ目', 'A', 0, 5.0]]),
            fn () => throw new RuntimeException('接続先の誤り'),
        ]);

        $run = $this->executeTranscriptionFailing($runId);

        $this->assertFailed($run, AiRunErrorCode::Provider, null, '0.000958');
        $this->assertNull($run->input_tokens);
        $output = json_decode((string) AiRunText::query()->findOrFail($runId)->output_text, true);
        $this->assertSame(['一つ目', '1-A'], [$output[0]['text'], $output[0]['speaker_label']]);
        $this->assertCount(1, $output);
        $this->assertSame([['failed', $level + 1]], $this->transcriptionKind->failedSeen);
    }

    public function test_a_timeout_of_the_provider_is_a_timeout(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload(self::PAUSED_SPEECH, 25));
        Transcription::fake([fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $this->assertFailed($this->executeTranscriptionFailing($runId), AiRunErrorCode::Timeout, null, '0.000458');
    }

    /**
     * 許す差 1 秒: 0〜5.5 秒の区切り(終わりの無音 0.5 秒)の最後の区間の終わりが 4 秒より前なら届いていない。区間が 0 なら終わり = 0 で、
     * 許す差より長い区切りは届いていない。費用は 5.5 秒の分。
     */
    public function test_a_response_that_does_not_reach_the_end_of_its_chunk_is_incomplete(): void
    {
        config(['ai_runs.transcription.incomplete_tolerance_seconds' => 1]);
        $early = $this->queuedTranscription($this->audioUpload(self::PAUSED_SPEECH, 25));
        $empty = $this->queuedTranscription($this->audioUpload(self::PAUSED_SPEECH, 25));
        Transcription::fake([
            $this->transcriptionResponse([['途中まで', 'A', 0, 3.9]]),
            $this->transcriptionResponse([]),
        ]);

        $run = $this->executeTranscriptionFailing($early, RuntimeException::class);
        $this->assertFailed($run, AiRunErrorCode::Incomplete, FailedRun::OUTPUT_TRUNCATED, '0.000458');
        $this->assertSame([10, 5], [$run->input_tokens, $run->output_tokens]);
        $this->transcriptionKind->failed = [];

        $run = $this->executeTranscriptionFailing($empty, RuntimeException::class);
        $this->assertFailed($run, AiRunErrorCode::Incomplete, FailedRun::OUTPUT_TRUNCATED, '0.000458');
    }

    public function test_an_audio_deleted_after_the_request_is_a_conversion_failure_without_cost(): void
    {
        $uploadId = $this->audioUpload(self::PAUSED_SPEECH, 25);
        $runId = $this->queuedTranscription($uploadId);
        Upload::query()->whereKey($uploadId)->update(['purged_at' => now(), 'purge_due_on' => today()]);
        Transcription::fake();

        $run = $this->executeTranscriptionFailing($runId);

        $this->assertFailed($run, AiRunErrorCode::Conversion, null, '0.000000');
        $this->assertNotNull($run->pricing);
        $this->assertNull(AiRunText::query()->findOrFail($runId)->output_text);
        Transcription::assertNothingGenerated();
    }

    public function test_an_exception_of_the_receiver_rolls_back_its_writes_and_is_a_completion_failure(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload('sin(2*PI*440*t)', 6));
        Transcription::fake([$this->transcriptionResponse([['発言', 'A', 0, 5.9]])]);
        $this->transcriptionKind->onComplete = function () use ($runId): void {
            AiRunText::query()->whereKey($runId)->update(['system_text' => '受け手が書いた']);

            throw new RuntimeException('区間を解釈できない');
        };

        $run = $this->executeTranscriptionFailing($runId, RuntimeException::class);

        $this->assertFailed($run, AiRunErrorCode::Completion, null, '0.000500');
        $this->assertSame('', AiRunText::query()->findOrFail($runId)->system_text);
    }

    public function test_a_requester_inactive_on_the_day_fails_before_the_receiver(): void
    {
        $retired = $this->user([['2020-01-01', '2021-01-01']]);
        $context = $this->transcriberContext($retired->id);
        $runId = $this->queuedTranscription($this->audioUpload('sin(2*PI*440*t)', 6), context: $context);
        Transcription::fake([$this->transcriptionResponse([['発言', 'A', 0, 5.9]])]);

        $run = $this->executeTranscriptionFailing($runId);

        $this->assertFailed($run, AiRunErrorCode::Completion, FailedRun::REQUESTER_INACTIVE, '0.000500');
        $this->assertSame([], $this->transcriptionKind->completed);
    }

    public function test_a_lost_worker_fails_the_run_once_and_a_later_call_changes_nothing(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload('sin(2*PI*440*t)', 6));

        (new RunTranscriptionJob($runId, 100))->failed(new RuntimeException('worker が落ちた'));
        (new RunTranscriptionJob($runId, 100))->failed(new RuntimeException('もう一度'));

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::WorkerLost], [$run->status, $run->error_code]);
        $this->assertNull($run->cost_usd);
        $this->assertCount(1, $this->transcriptionKind->failed);
    }

    private function assertFailed(AiRun $run, AiRunErrorCode $errorCode, ?string $reason, string $costUsd): void
    {
        $this->assertSame([AiRunStatus::Failed, $errorCode], [$run->status, $run->error_code]);
        $this->assertSame($costUsd, $run->cost_usd);
        $failed = array_map(
            fn (FailedRun $failed): array => [$failed->runId, $failed->errorCode, $failed->reason],
            $this->transcriptionKind->failed,
        );
        $this->assertSame([[$run->id, $errorCode->value, $reason]], $failed);
        $this->assertNoTranscriptionFilesLeft();
    }
}

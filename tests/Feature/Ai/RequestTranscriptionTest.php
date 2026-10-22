<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestTranscription;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\Api\TranscriptionRequest;
use App\Actions\Ai\RunBudget;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunTranscriptionJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Master\Department;
use App\Models\Upload\Upload;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\TranscriptionSettings;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Transcription;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 文字起こしの依頼(docs の 設計/AI/公開の口.md 3 節の文字起こしの依頼の行、実行と費用.md 4.2・5.2): ゲストは 403、登録の無い種類と音声の形式でない添付は
 * 作りの誤り、実体が無い・読み取れない・長すぎるは理由のコードつきの 422、区分の上限は文字起こしの文の 422(見積もり = 長さ ÷ 60 × 0.005 ドルで数え、
 * 終わる前の実行は見積もりで数え、区分をまたいで数えない)、実行(queued)と空の送る文を書き、ジョブはコミットの後に出る。長さは勧告ロックの外で測る。
 */
final class RequestTranscriptionTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestTranscriptionKinds;

    /** 6 秒の音声の見積もり(6 ÷ 60 × 0.005) */
    private const RESERVED = 0.0005;

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

    public function test_a_guest_cannot_request(): void
    {
        Queue::fake();

        try {
            app(RequestTranscription::class)->execute($this->guestContext(), $this->request($this->sine()));
            $this->fail('ゲストが依頼できた');
        } catch (AuthorizationException) {
            $this->assertSame(0, AiRun::query()->count());
        }
    }

    public function test_an_unregistered_kind_and_an_attachment_that_is_not_audio_are_logic_errors(): void
    {
        $kinds = (array) config('ai_runs.kinds');
        config(['ai_runs.kinds' => [...$kinds, 'test.unregistered' => $kinds[RecordingTranscriptionKind::CODE]]]);
        $uploadId = $this->sine();
        $pdf = Upload::factory()->create()->id;
        $calls = [
            fn () => $this->execute(new TranscriptionRequest('test.unregistered', $this->recordId(), $uploadId)),
            fn () => $this->execute($this->request($pdf)),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('作りの誤りにならなかった');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_audio_without_its_file_is_declined_as_missing(): void
    {
        $purged = Upload::factory()->purged()->create(['mime' => 'audio/mpeg'])->id;
        $withoutFile = Upload::factory()->create(['mime' => 'audio/mpeg', 'path' => 'ai-test/none.mp3'])->id;

        foreach ([$purged, $withoutFile, 999_999] as $uploadId) {
            $this->assertDeclined($uploadId, RunRequestDeclined::MISSING, '音声が削除されたため、処理できません');
        }
    }

    public function test_a_broken_audio_is_declined_as_unreadable(): void
    {
        Storage::disk('local')->put('ai-test/broken.mp3', 'これは音声ではない');
        $uploadId = Upload::factory()->create(['mime' => 'audio/mpeg', 'path' => 'ai-test/broken.mp3'])->id;

        $this->assertDeclined($uploadId, RunRequestDeclined::UNREADABLE, '音声を読み取れませんでした');
    }

    /** 1 本の長さの上限 1 時間を 1 秒超える(8kHz・8 ビットの wav で作る) */
    public function test_an_audio_over_the_maximum_length_is_declined_with_the_hours(): void
    {
        $path = 'ai-test/long.wav';
        Storage::disk('local')->makeDirectory('ai-test');
        $this->assertSame(0, Process::run([
            'ffmpeg', '-hide_banner', '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=8000:cl=mono',
            '-t', '3601', '-c:a', 'pcm_u8', Storage::disk('local')->path($path),
        ])->exitCode());
        $uploadId = Upload::factory()->create(['mime' => 'audio/wav', 'path' => $path])->id;

        $this->assertDeclined($uploadId, RunRequestDeclined::TOO_LONG, '音声が長すぎます。1 時間までの音声を取り込んでください');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function periods(): array
    {
        return [
            '日' => ['daily_usd', RunBudget::TRANSCRIPTION_DAILY_EXCEEDED],
            '月' => ['monthly_usd', RunBudget::TRANSCRIPTION_MONTHLY_EXCEEDED],
        ];
    }

    #[DataProvider('periods')]
    public function test_up_to_the_limit_passes_and_beyond_is_declined(string $period, string $message): void
    {
        Queue::fake();
        $uploadId = $this->sine();
        AiRun::factory()->create(['budget' => 'transcription', 'reserved_cost_usd' => '0.300000']);
        AiRun::factory()->succeeded('9.000000')->create(['budget' => 'default']);

        config(["ai_runs.budget.transcription.{$period}" => round(0.3 + self::RESERVED - 0.000001, 6)]);
        $this->assertDeclined($uploadId, RunRequestDeclined::BUDGET, $message);

        config(["ai_runs.budget.transcription.{$period}" => round(0.3 + self::RESERVED, 6)]);
        $this->execute($this->request($uploadId));
        $this->assertSame(3, AiRun::query()->count());
    }

    public function test_writes_a_queued_run_with_the_audio_its_length_and_the_estimate(): void
    {
        Queue::fake();
        $uploadId = $this->sine();
        $record = $this->transcriptionRecord();
        $department = Department::factory()->create();

        $runId = app(RequestTranscription::class)->execute(
            $this->transcriberContext(selectedDepartmentId: $department->id),
            new TranscriptionRequest(RecordingTranscriptionKind::CODE, $record->id, $uploadId),
        );

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame(
            [AiRunStatus::Queued, $record->id, $record->id, 'ai_audio_notes'],
            [$run->status, $run->record_id, $run->original_record_id, $run->record_module_code],
        );
        $this->assertSame([$record->id], $run->source_record_ids);
        $this->assertSame(
            [$uploadId, $uploadId, '6.000', null],
            [$run->input_upload_id, $run->original_input_upload_id, $run->audio_seconds, $run->max_output_tokens],
        );
        $this->assertSame(
            ['gemini', 'gemini-3.5-transcribe', 'transcription', $department->id, '0.000500'],
            [$run->provider, $run->model, $run->budget, $run->selected_department_id, $run->reserved_cost_usd],
        );
        $text = AiRunText::query()->findOrFail($runId);
        $this->assertSame(['', '', null], [$text->system_text, $text->user_text, $text->output_text]);
        $kind = app(KindSettings::class)->transcription(RecordingTranscriptionKind::CODE);
        $this->assertNotNull($kind);
        $timeout = app(TranscriptionSettings::class)->jobTimeout($kind);
        Queue::assertPushed(
            RunTranscriptionJob::class,
            fn (RunTranscriptionJob $job): bool => [$job->runId, $job->timeout] === [$runId, $timeout],
        );
    }

    public function test_the_length_is_measured_before_the_advisory_lock(): void
    {
        Queue::fake();
        $request = $this->request($this->sine());
        $events = [];
        DB::listen(function (QueryExecuted $query) use (&$events): void {
            $events[] = str_contains($query->sql, 'pg_advisory_xact_lock') ? 'lock' : 'query';
        });
        Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$events): void {
            $events[] = str_contains($logged->message, '音声の長さを測った') ? 'measured' : 'log';
        });

        $this->execute($request);

        $measured = array_search('measured', $events, true);
        $this->assertIsInt($measured);
        $this->assertLessThan(array_search('lock', $events, true), $measured);
    }

    public function test_the_job_runs_only_after_the_callers_transaction_commits(): void
    {
        $uploadId = $this->sine();
        Transcription::fake([$this->transcriptionResponse([['こんにちは', 'A', 0, 5.5]])]);

        $runId = DB::transaction(function () use ($uploadId): int {
            $runId = $this->execute($this->request($uploadId));

            Transcription::assertNothingGenerated();
            $this->assertSame(AiRunStatus::Queued, AiRun::query()->findOrFail($runId)->status);

            return $runId;
        });

        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($runId)->status);
        $this->assertCount(1, $this->transcriptionKind->completed);
    }

    public function test_no_job_runs_when_the_callers_transaction_rolls_back(): void
    {
        $uploadId = $this->sine();
        Transcription::fake([$this->transcriptionResponse([['こんにちは', 'A', 0, 5.5]])]);

        try {
            DB::transaction(function () use ($uploadId): void {
                $this->execute($this->request($uploadId));

                throw new RuntimeException('呼び手の失敗');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        Transcription::assertNothingGenerated();
        $this->assertSame(0, AiRun::query()->count());
    }

    /** 6 秒の正弦波の音声 */
    private function sine(): int
    {
        return $this->audioUpload('sin(2*PI*440*t)', 6);
    }

    private function request(int $uploadId): TranscriptionRequest
    {
        return new TranscriptionRequest(RecordingTranscriptionKind::CODE, $this->recordId(), $uploadId);
    }

    private function recordId(): int
    {
        return $this->transcriptionRecord()->id;
    }

    private function execute(TranscriptionRequest $request): int
    {
        return app(RequestTranscription::class)->execute($this->transcriberContext(), $request);
    }

    private function assertDeclined(int $uploadId, string $reason, string $message): void
    {
        $before = AiRun::query()->count();

        try {
            $this->execute($this->request($uploadId));
            $this->fail('依頼が断られなかった');
        } catch (RunRequestDeclined $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame([RunBudget::ERROR_KEY => [$message]], $exception->errors());
        }

        $this->assertSame($before, AiRun::query()->count());
    }

    private function guestContext(): AccessContext
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext(null, null, null, $today, AccessContext::LOCALE_CODE);
    }
}

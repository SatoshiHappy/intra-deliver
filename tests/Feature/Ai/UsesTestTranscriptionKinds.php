<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestTranscription;
use App\Actions\Ai\Api\TranscriptionKind;
use App\Actions\Ai\Api\TranscriptionRequest;
use App\Actions\Ai\ExecuteTranscription;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TranscriptionSegment;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\TranscriptionResponse as Response;
use LogicException;
use Throwable;

/**
 * Ai のテストの文字起こしの種類を登録する手助け(種類の実装はテストの中だけに置く)。test.transcription を Gemini 3.5 Transcribe(公開デモの単価
 * = 1 分 0.005 ドル)・予算の区分 transcription・呼び出しの時間の上限 30 秒・1 本の長さの上限 1 時間・区切りの長さ 10 秒で設定に足し、無音を探す幅を 2 秒、
 * 一時ファイルの置き場をテストごとのディレクトリにし、上限は既定で無しにする。音声はテストの中で ffmpeg の lavfi で作り、添付の台帳に置く。
 */
trait UsesTestTranscriptionKinds
{
    protected RecordingTranscriptionKind $transcriptionKind;

    protected string $transcriptionTemp;

    protected function registerTestTranscriptionKind(): void
    {
        $this->transcriptionKind = new RecordingTranscriptionKind;
        $this->app->instance(RecordingTranscriptionKind::class, $this->transcriptionKind);
        $this->app->tag([RecordingTranscriptionKind::class], TranscriptionKind::TAG);
        $this->transcriptionTemp = sys_get_temp_dir().'/ai-transcription-test-'.bin2hex(random_bytes(6));
        Storage::fake('local');
        $unlimited = ['daily_usd' => null, 'monthly_usd' => null, 'total_usd' => null];
        config([
            'ai_runs.kinds' => [...(array) config('ai_runs.kinds'), RecordingTranscriptionKind::CODE => [
                'type' => 'transcription',
                'provider' => 'gemini',
                'model' => 'gemini-3.5-transcribe',
                'budget' => 'transcription',
                'timeout_seconds' => 30,
                'language' => 'ja',
                'max_audio_seconds' => 3600,
                'chunk_seconds' => 10,
            ]],
            'ai_runs.budget' => ['default' => $unlimited, 'transcription' => $unlimited],
            'ai_runs.transcription.silence_window_seconds' => 2,
            'ai_runs.transcription.temp_directory' => $this->transcriptionTemp,
        ]);
    }

    protected function removeTranscriptionTemp(): void
    {
        File::deleteDirectory($this->transcriptionTemp);
    }

    /**
     * lavfi の式の音声(16kHz・モノラル)を作り、添付の台帳の行にする。
     */
    protected function audioUpload(string $expression, int $seconds, string $mime = 'audio/wav'): int
    {
        $path = 'ai-test/'.bin2hex(random_bytes(6)).'.wav';
        File::ensureDirectoryExists(dirname(Storage::disk('local')->path($path)));
        $result = Process::run([
            'ffmpeg', '-hide_banner', '-nostdin', '-v', 'error', '-y',
            '-f', 'lavfi', '-i', "aevalsrc='{$expression}':s=16000:d={$seconds}", Storage::disk('local')->path($path),
        ]);
        $this->assertTrue($result->successful(), $result->errorOutput());

        $size = Storage::disk('local')->size($path);

        return Upload::factory()->create(['path' => $path, 'mime' => $mime, 'size' => $size])->id;
    }

    /** 対象のレコード(業務モジュール ai_audio_notes。業務モジュールはテストごとに 1 つ作って使い回す) */
    protected function transcriptionRecord(): Record
    {
        $moduleId = RecordModule::query()->where('code', 'ai_audio_notes')->value('id')
            ?? $this->recordModule('ai_audio_notes')->id;

        return Record::factory()->create(['record_module_id' => $moduleId]);
    }

    /**
     * 依頼して待ちの実行を作る(ジョブは出さない)。
     */
    protected function queuedTranscription(int $uploadId, ?int $recordId = null, ?AccessContext $context = null): int
    {
        Queue::fake();
        $recordId ??= $this->transcriptionRecord()->id;
        $request = new TranscriptionRequest(RecordingTranscriptionKind::CODE, $recordId, $uploadId);

        return app(RequestTranscription::class)->execute($context ?? $this->transcriberContext(), $request);
    }

    /**
     * 接続先の応答(区間 = [文, 話者, 開始の秒, 終了の秒])。
     *
     * @param  list<array{string, string, float|int, float|int}>  $segments
     */
    protected function transcriptionResponse(array $segments, int $inputTokens = 10, int $outputTokens = 5): Response
    {
        return new Response(
            implode(' ', array_column($segments, 0)),
            new Collection(array_map(fn (array $segment): TranscriptionSegment => new TranscriptionSegment(
                $segment[0],
                $segment[1],
                (float) $segment[2],
                (float) $segment[3],
            ), $segments)),
            new TranscriptionUsage($inputTokens, $outputTokens),
            new Meta('gemini', 'gemini-3.5-transcribe'),
        );
    }

    /**
     * 失敗する文字起こしを動かし、投げ直された例外を確かめて実行を読み直す。
     *
     * @param  class-string<Throwable>  $expected
     */
    protected function executeTranscriptionFailing(int $runId, string $expected = Throwable::class): AiRun
    {
        try {
            app(ExecuteTranscription::class)->execute($runId);
            $this->fail('失敗が投げ直されなかった');
        } catch (Throwable $exception) {
            $this->assertInstanceOf($expected, $exception);

            if ($expected === Throwable::class) {
                $this->assertNotInstanceOf(LogicException::class, $exception);
            }
        }

        return AiRun::query()->findOrFail($runId);
    }

    protected function transcriberContext(
        ?int $userId = null,
        ?int $selectedDepartmentId = null,
        ?string $visitorId = null,
    ): AccessContext {
        $userId ??= (int) User::query()->orderBy('id')->value('id');

        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($userId, null, $selectedDepartmentId, $today, AccessContext::LOCALE_CODE, $visitorId);
    }

    /** 区切りの一時ファイルの置き場に、ファイルが残っていない */
    protected function assertNoTranscriptionFilesLeft(): void
    {
        $left = File::isDirectory($this->transcriptionTemp) ? File::allFiles($this->transcriptionTemp) : [];
        $this->assertSame([], $left);
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\CompletedTranscription;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\TranscriptionKind;
use App\Models\Ai\AiRun;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * テストの文字起こしの種類(コード test.transcription)。complete と failed に渡された値と、そのときの実行の状態・トランザクションの深さを覚える。
 * complete の中身と書いてよい業務モジュール・failed の例外はテストが差し替える。
 */
final class RecordingTranscriptionKind implements TranscriptionKind
{
    public const CODE = 'test.transcription';

    /** 書いてよい業務モジュール(テストが置く) */
    public ?string $writesTo = null;

    /** @var (Closure(CompletedTranscription): void)|null complete の中で走らせる処理(書き込みの口を呼ぶ・例外を投げる) */
    public ?Closure $onComplete = null;

    /** failed が投げる例外(テストが置く) */
    public ?Throwable $failedThrows = null;

    /** @var list<CompletedTranscription> */
    public array $completed = [];

    /** @var list<string> complete の中で読んだ実行の状態 */
    public array $statusesSeen = [];

    /** @var list<FailedRun> */
    public array $failed = [];

    /** @var list<array{string, int}> failed の中で読んだ実行の状態と、トランザクションの深さ */
    public array $failedSeen = [];

    public function code(): string
    {
        return self::CODE;
    }

    public function writesTo(): ?string
    {
        return $this->writesTo;
    }

    public function complete(CompletedTranscription $run): void
    {
        $this->completed[] = $run;
        $this->statusesSeen[] = AiRun::query()->findOrFail($run->runId)->status->value;

        if ($this->onComplete !== null) {
            ($this->onComplete)($run);
        }
    }

    public function failed(FailedRun $run): void
    {
        $this->failed[] = $run;
        $this->failedSeen[] = [AiRun::query()->findOrFail($run->runId)->status->value, DB::transactionLevel()];

        if ($this->failedThrows !== null) {
            throw $this->failedThrows;
        }
    }
}

<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\CompletedTranscription;
use App\Actions\Ai\Api\TranscriptionKind;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\TranscriptionSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use LogicException;
use Throwable;

/**
 * AI の文字起こしの実行を動かす(ジョブ App\Jobs\Ai\RunTranscriptionJob から呼ぶ。手順と失敗の扱いの正本 = docs の 設計/AI/実行と費用.md 4 節、
 * データと失敗と検査.md 7 節)。変換と接続先を待つ間は鍵もトランザクションも持たない。完了の順と失敗の書き方は文章の種類と同じ部品
 * ({@see CompleteRun}・{@see FailRun})。区切りの一時ファイルは成功でも失敗でも実行の終わりに消す(データと失敗と検査.md 6 節)。
 *
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 */
class ExecuteTranscription
{
    public function __construct(
        private TranscriptionKinds $kinds,
        private KindSettings $kindSettings,
        private TranscriptionSettings $settings,
        private TranscribeAudio $transcribe,
        private FailRun $failRun,
        private CompleteRun $completeRun,
        private RunRequesterContext $requesters,
    ) {}

    /**
     * @throws LogicException 実行が queued でない・種類の実装か設定が無いとき
     * @throws Throwable 変換・接続先の呼び出し・不完全・結果の書き込みの失敗(実行は failed に書いた後)
     */
    public function execute(int $runId): void
    {
        [$run, $kind, $settings] = $this->start($runId);

        if ($kind === null || $settings === null) {
            $notRegistered = new LogicException(TranscriptionKinds::notRegistered($run->kind).'か、設定に無い');
            $this->failRun->execute($runId, AiRunErrorCode::Completion, $notRegistered);

            throw $notRegistered;
        }

        $directory = $this->settings->tempDirectory().'/'.$runId;

        try {
            $this->transcribeAndComplete($run, $kind, $settings, $directory);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * 実行を running にし、種類の実装と設定と一緒に返す。どちらかが引けなければ状態を変えない(呼び手が completion にする)。
     *
     * @return array{AiRun, TranscriptionKind|null, TranscriptionKindSettings|null}
     *
     * @throws LogicException 実行が queued でないとき
     */
    private function start(int $runId): array
    {
        return DB::transaction(function () use ($runId): array {
            $run = AiRun::query()->lockForUpdate()->findOrFail($runId);

            if ($run->status !== AiRunStatus::Queued) {
                throw new LogicException("AI の実行 {$runId} は queued でない({$run->status->value})");
            }

            $kind = $this->kinds->find($run->kind);
            $settings = $kind === null ? null : $this->kindSettings->transcription($run->kind);

            if ($settings !== null) {
                $run->fill(['status' => AiRunStatus::Running, 'started_at' => now()])->save();
            }

            return [$run, $kind, $settings];
        });
    }

    /**
     * @param  TranscriptionKindSettings  $settings
     *
     * @throws Throwable 変換・呼び出し・不完全・完了の失敗(実行は failed に書いた後)
     */
    private function transcribeAndComplete(
        AiRun $run,
        TranscriptionKind $kind,
        array $settings,
        string $directory,
    ): void {
        try {
            $outcome = $this->transcribe->execute($run, $settings, $directory);
        } catch (TranscriptionStopped $stopped) {
            $this->failRun->execute($run->id, $stopped->errorCode, $stopped->failure, $stopped->result);

            throw $stopped->failure;
        }

        try {
            $receiver = fn (AiRun $running) => $kind->complete(new CompletedTranscription(
                $running->id,
                $running->kind,
                $running->record_id,
                $running->requester_user_id,
                $outcome->segments,
                $outcome->chunkCount,
                (float) $running->audio_seconds,
                $this->requesters->of($running),
            ));
            $completed = $this->completeRun->execute($run->id, $receiver, $outcome->result);
        } catch (Throwable $exception) {
            $this->failRun->execute($run->id, AiRunErrorCode::Completion, $exception, $outcome->result);

            throw $exception;
        }

        if (! $completed) {
            report(new LogicException("AI の実行 {$run->id} は完了の前にほかの経路で終わっていた(使用量だけを書いた)"));
        }
    }
}

<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Enums\Ai\AiRunErrorCode;
use App\Models\Ai\AiRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AI の実行を失敗にする 1 か所(docs の 設計/AI/実行と費用.md 4 節の「失敗の書き方」、受け手 = 公開の口.md 3.1、データと失敗と検査.md 7 節)。
 * 受け手の例外を投げずに報告するのは、呼び手が投げ直す元の例外を隠さないため。
 */
class FailRun
{
    public function __construct(
        private MarkRunFailed $markRunFailed,
        private PromptKinds $kinds,
        private TranscriptionKinds $transcriptionKinds,
    ) {}

    /**
     * 失敗の書き込みに失敗したら、元の例外を report() してから書き込みの例外を投げる(元の例外をログから失わない)。
     *
     * @param  RunResult|null  $result  分かった分の使用量・費用・原文(不完全のときは細かい理由も)
     *
     * @throws Throwable 失敗の書き込みに失敗したとき
     */
    public function execute(
        int $runId,
        AiRunErrorCode $errorCode,
        ?Throwable $exception,
        ?RunResult $result = null,
    ): void {
        try {
            $receiverError = $this->failWithReceiver($runId, $errorCode, $exception, $result);

            if ($receiverError !== null) {
                $this->failWithoutReceiver($runId, $errorCode, $exception, $result, $receiverError);
            }
        } catch (Throwable $writeError) {
            if ($exception !== null) {
                report($exception);
            }

            throw $writeError;
        }
    }

    /**
     * @return Throwable|null 受け手の例外(巻き戻した後。失敗は呼び手が書く)
     *
     * @throws Throwable 読み書きの失敗(巻き戻す)
     */
    private function failWithReceiver(
        int $runId,
        AiRunErrorCode $errorCode,
        ?Throwable $exception,
        ?RunResult $result,
    ): ?Throwable {
        DB::beginTransaction();

        try {
            $run = AiRun::query()->findOrFail($runId);

            if ($run->status->isFinished()) {
                DB::commit();

                return null;
            }

            $receiverError = $this->callReceiver($run, $errorCode, $exception, $result);

            if ($receiverError !== null) {
                try {
                    DB::rollBack();
                } catch (Throwable $rollbackError) {
                    report($receiverError);

                    throw $rollbackError;
                }

                return $receiverError;
            }

            if ($this->markRunFailed->execute($runId, $errorCode, $exception, $result)) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return null;
        } catch (Throwable $writeError) {
            DB::rollBack();

            throw $writeError;
        }
    }

    /**
     * 実装が引けなければ呼ばない(作りの誤りの報告は呼び手の経路 = データと失敗と検査.md 7 節)。準備(実装を引く・渡す値を作る)の例外も受け手の例外と
     * 同じに返す(準備で投げても、失敗だけは書けるように)。
     */
    private function callReceiver(
        AiRun $run,
        AiRunErrorCode $errorCode,
        ?Throwable $exception,
        ?RunResult $result,
    ): ?Throwable {
        return rescue(function () use ($run, $errorCode, $exception, $result): null {
            $kind = $this->kinds->find($run->kind) ?? $this->transcriptionKinds->find($run->kind);

            $kind?->failed(new FailedRun(
                $run->id,
                $run->kind,
                $run->record_id,
                $run->requester_user_id,
                $errorCode->value,
                self::reasonOf($errorCode, $exception, $result),
            ));

            return null;
        }, fn (Throwable $receiverError): Throwable => $receiverError, report: false);
    }

    /**
     * 受け手の例外は、書き込みが失敗しても報告する。
     *
     * @throws Throwable 書き込みに失敗したとき
     */
    private function failWithoutReceiver(
        int $runId,
        AiRunErrorCode $errorCode,
        ?Throwable $exception,
        ?RunResult $result,
        Throwable $receiverError,
    ): void {
        try {
            $this->markRunFailed->execute($runId, $errorCode, $exception, $result);
        } finally {
            report($receiverError);
        }
    }

    /**
     * 細かい理由(会議の通知の文を選ぶ値)。完了の処理の失敗は例外の型だけで決め、例外の連なり(previous)は辿らない。
     */
    private static function reasonOf(AiRunErrorCode $errorCode, ?Throwable $exception, ?RunResult $result): ?string
    {
        return match ($errorCode) {
            AiRunErrorCode::Incomplete => $result?->incompleteReason,
            AiRunErrorCode::Completion => self::completionReason($exception),
            default => null,
        };
    }

    private static function completionReason(?Throwable $exception): ?string
    {
        return match (true) {
            $exception instanceof RunCompletionFailed => $exception->reason,
            $exception instanceof RunRequestDeclined => $exception->reason,
            $exception instanceof AuthorizationException => FailedRun::RECORD_FORBIDDEN,
            $exception instanceof ModelNotFoundException => FailedRun::RECORD_NOT_FOUND,
            $exception instanceof ValidationException => FailedRun::RECORD_REJECTED,
            default => null,
        };
    }
}

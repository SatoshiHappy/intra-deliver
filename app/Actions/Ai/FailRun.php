<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\ConversationFailed;
use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\PromptKind;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\Api\TranscriptionKind;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunErrorCode;
use App\Models\Ai\AiRun;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AI の実行を失敗にする 1 か所(docs の 設計/AI/実行と費用/README.md 4 節の「完了と失敗の書き方」、受け手 = 公開の口.md 3.1・
 * 設計/AI/エージェントの実行/口と契約.md 9.13、データと失敗と検査.md 7 節)。受け手の例外を投げずに報告するのは、呼び手が投げ直す元の例外を隠さないため。
 * 終わった実行(会話の型の確認待ち・止めたを含む)は変えず、受け手を呼ばない(表の F5)。会話の型の失敗の後始末の置き場は、失敗を書く {@see MarkRunFailed}。
 */
class FailRun
{
    public function __construct(
        private MarkRunFailed $markRunFailed,
        private PromptKinds $kinds,
        private TranscriptionKinds $transcriptionKinds,
        private ConversationKinds $conversationKinds,
        private FailingRun $failing,
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
            if (! $this->failWithReceiver($runId, $errorCode, $exception, $result)) {
                $this->markRunFailed->execute($runId, $errorCode, $exception, $result);
            }
        } catch (Throwable $writeError) {
            if ($exception !== null) {
                report($exception);
            }

            throw $writeError;
        }
    }

    /**
     * @return bool 受け手が投げずに終わった(か、実行がもう終わっていた)= true。受け手が投げた = false(巻き戻した後。失敗は呼び手が書く)
     *
     * @throws Throwable 読み書きの失敗(巻き戻す)
     */
    private function failWithReceiver(
        int $runId,
        AiRunErrorCode $errorCode,
        ?Throwable $exception,
        ?RunResult $result,
    ): bool {
        DB::beginTransaction();

        try {
            $run = AiRun::query()->findOrFail($runId);

            if ($run->status->isFinished()) {
                DB::commit();

                return true;
            }

            if (! $this->callReceiver($run, $errorCode, $exception, $result)) {
                DB::rollBack();

                return false;
            }

            if ($this->markRunFailed->execute($runId, $errorCode, $exception, $result)) {
                DB::commit();
            } else {
                DB::rollBack();
            }

            return true;
        } catch (Throwable $writeError) {
            rescue(fn () => DB::rollBack());

            throw $writeError;
        }
    }

    /**
     * 実装が引けなければ呼ばない(作りの誤りの報告は呼び手の経路 = データと失敗と検査.md 7 節)。準備(実装を引く・渡す値を作る)の例外も受け手の例外と
     * 同じに返す(準備で投げても、失敗だけは書けるように)。受け手の例外は捕まえた所で報告する(後の書き込みが失敗しても残るように)。
     * 受け手を呼ぶ間は失敗を書いている実行の印を付ける(受け手の中からの依頼を依頼の口が断る = 設計/AI/公開の口.md 3 節の表の B7)。
     *
     * @return bool 投げずに終わった = true
     */
    private function callReceiver(
        AiRun $run,
        AiRunErrorCode $errorCode,
        ?Throwable $exception,
        ?RunResult $result,
    ): bool {
        return rescue(function () use ($run, $errorCode, $exception, $result): bool {
            $receiver = $this->receiverOf($run, $errorCode->value, self::reasonOf($errorCode, $exception, $result));

            if ($receiver !== null) {
                $this->failing->during($run->id, $receiver);
            }

            return true;
        }, false);
    }

    /**
     * 種類の失敗の受け手を呼ぶ処理(実装が引けなければ null)。受け手は行の種類の型で決めた型の種類の一覧から引く(型をまたいで探さない。
     * その型に登録が無ければ、登録が無いの扱い)。会話の型の種類には対象のレコードの代わりに会話の題を渡す(題を渡すのは会話のジョブ。ここからは無し)。
     *
     * @return (Closure(): void)|null
     */
    private function receiverOf(AiRun $run, string $errorCode, ?string $reason): ?Closure
    {
        $failed = fn (): FailedRun => new FailedRun(
            $run->id, $run->kind, $run->record_id, $run->requester_user_id, $errorCode, $reason,
        );

        return match ($run->kind_type) {
            AiKindType::Prompt => self::recordReceiver($this->kinds->find($run->kind), $failed),
            AiKindType::Transcription => self::recordReceiver($this->transcriptionKinds->find($run->kind), $failed),
            AiKindType::Conversation => self::conversationReceiver(
                $this->conversationKinds->find($run->kind),
                new ConversationFailed($run->id, $run->kind, $run->requester_user_id, $errorCode, $reason, null),
            ),
        };
    }

    /**
     * 文章・文字起こしの種類の失敗の受け手を呼ぶ処理(実装が無ければ null)。
     *
     * @param  Closure(): FailedRun  $failed
     * @return (Closure(): void)|null
     */
    private static function recordReceiver(PromptKind|TranscriptionKind|null $kind, Closure $failed): ?Closure
    {
        return $kind === null ? null : fn () => $kind->failed($failed());
    }

    /**
     * 会話の型の種類の失敗の受け手を呼ぶ処理(実装が無ければ null)。
     *
     * @return (Closure(): void)|null
     */
    private static function conversationReceiver(?ConversationKind $kind, ConversationFailed $failed): ?Closure
    {
        return $kind === null ? null : fn () => $kind->failed($failed);
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
            $exception instanceof ModelNotFoundException => FailedRun::RECORD_NOT_FOUND,
            $exception instanceof ValidationException => FailedRun::RECORD_REJECTED,
            default => null,
        };
    }
}

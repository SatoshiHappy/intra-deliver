<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\Api\RunRequestRejected;
use App\Enums\Ai\AiRunErrorCode;
use App\Models\Ai\AiRun;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AI の実行を失敗にし、失敗にしたときだけ種類の失敗の受け手を呼ぶ 1 か所(docs の 設計/AI/公開の口.md 3.1、データと失敗と検査.md 7 節)。
 * ジョブの failed() との二重呼びで受け手を 2 回呼ばないよう、失敗にしたとき(MarkRunFailed が書いたとき)だけ呼ぶ。
 * 受け手の例外は report() して続け、呼び手が投げ直す元の例外を隠さない。
 * 種類の実装が引けない実行は受け手を呼べない(ExecutePrompt の経路では、呼び手が投げる例外で作りの誤りとして報告される = データと失敗と検査.md 7 節)。
 */
class FailRun
{
    public function __construct(private MarkRunFailed $markRunFailed, private PromptKinds $kinds) {}

    /**
     * @param  LlmResult|null  $result  応答(使用量が分かったとき)
     *
     * @throws Throwable 失敗の書き込みに失敗したとき({@see MarkRunFailed})
     */
    public function execute(
        int $runId,
        AiRunErrorCode $errorCode,
        ?Throwable $exception,
        ?LlmResult $result = null,
    ): void {
        if (! $this->markRunFailed->execute($runId, $errorCode, $exception, $result)) {
            return;
        }

        $run = AiRun::query()->findOrFail($runId);
        $kind = $this->kinds->find($run->kind);

        if ($kind === null) {
            return;
        }

        $failed = new FailedRun(
            $run->id,
            $run->kind,
            $run->record_id,
            $run->requester_user_id,
            $errorCode->value,
            self::reasonOf($errorCode, $exception, $result),
        );

        rescue(fn () => DB::transaction(fn () => $kind->failed($failed)), report: true);
    }

    /**
     * 細かい理由(会議の通知の文を選ぶ値)。完了の処理の失敗は例外の型だけで決め、例外の連なり(previous)は辿らない。
     */
    private static function reasonOf(AiRunErrorCode $errorCode, ?Throwable $exception, ?LlmResult $result): ?string
    {
        return match ($errorCode) {
            AiRunErrorCode::Incomplete => self::incompleteReason($result),
            AiRunErrorCode::Completion => self::completionReason($exception),
            default => null,
        };
    }

    private static function incompleteReason(?LlmResult $result): ?string
    {
        return match (true) {
            $result?->isTruncated() === true => FailedRun::OUTPUT_TRUNCATED,
            $result?->isRefused() === true => FailedRun::CONTENT_REFUSED,
            default => null,
        };
    }

    private static function completionReason(?Throwable $exception): ?string
    {
        return match (true) {
            $exception instanceof RunCompletionFailed => $exception->reason,
            $exception instanceof RunRequestRejected => $exception->reason,
            $exception instanceof AuthorizationException => FailedRun::RECORD_FORBIDDEN,
            $exception instanceof ModelNotFoundException => FailedRun::RECORD_NOT_FOUND,
            $exception instanceof ValidationException => FailedRun::RECORD_REJECTED,
            default => null,
        };
    }
}

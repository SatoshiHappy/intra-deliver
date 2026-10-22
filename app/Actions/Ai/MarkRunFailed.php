<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AI の実行を失敗にする(docs の 設計/AI/実行と費用.md 4 節、データと失敗と検査.md 7 節)。
 * 終わった実行は変えず、失敗にしたかを返す(ジョブの failed() が、ExecutePrompt が失敗にした後にも呼ばれるため)。
 * 呼び手 = 失敗の受け手まで束ねる {@see FailRun} だけ。
 * 書き込みに失敗したら、元の例外を report() してから書き込みの例外を投げる(元の例外をログから失わない)。
 */
class MarkRunFailed
{
    private const MESSAGE_MAX_CHARS = 1000;

    /**
     * @param  RunResult|null  $result  分かった分の使用量・費用・原文(分からなければ null = 使用量・費用は NULL のまま見積もりで数える)
     * @return bool 失敗にした = true。もう終わっていた = false(何も書かない)
     *
     * @throws Throwable 書き込みに失敗したとき
     */
    public function execute(int $runId, AiRunErrorCode $errorCode, ?Throwable $exception, ?RunResult $result = null): bool
    {
        try {
            return DB::transaction(function () use ($runId, $errorCode, $exception, $result): bool {
                $run = AiRun::query()->lockForUpdate()->findOrFail($runId);

                if ($run->status->isFinished()) {
                    return false;
                }

                $run->fill([
                    ...($result->usage ?? []),
                    'status' => AiRunStatus::Failed,
                    'error_code' => $errorCode,
                    'error_message' => mb_substr($exception?->getMessage() ?? '', 0, self::MESSAGE_MAX_CHARS),
                    'finished_at' => now(),
                ])->save();

                if ($result?->outputText !== null) {
                    AiRunText::query()->whereKey($runId)->update(['output_text' => $result->outputText]);
                }

                return true;
            });
        } catch (Throwable $writeError) {
            if ($exception !== null) {
                report($exception);
            }

            throw $writeError;
        }
    }
}

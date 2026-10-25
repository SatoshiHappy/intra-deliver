<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 実行の行の鍵を取り、待ちか処理中なら失敗にする(docs の 設計/AI/実行と費用.md 4 節の「失敗の書き方」、データと失敗と検査.md 7 節)。
 * 終わった実行は変えず、失敗にしたかを返す(ジョブの failed() が、ExecutePrompt が失敗にした後にも呼ばれるため)。
 * 呼び手 = {@see FailRun} だけ(書き込みの失敗の報告も FailRun)。
 */
class MarkRunFailed
{
    private const MESSAGE_MAX_CHARS = 1000;

    /**
     * 自分のトランザクションを持つ(呼び手のトランザクションの中では savepoint)。
     *
     * @param  RunResult|null  $result  分かった分の使用量・費用・原文(分からなければ null = 使用量・費用は NULL のまま見積もりで数える)
     * @return bool 失敗にした = true。もう終わっていた = false(何も書かない)
     *
     * @throws Throwable 書き込みに失敗したとき
     */
    public function execute(int $runId, AiRunErrorCode $errorCode, ?Throwable $exception, ?RunResult $result = null): bool
    {
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
    }
}

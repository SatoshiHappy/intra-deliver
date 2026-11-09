<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * 実行の行の鍵を取り、待ちか処理中なら失敗にする(docs の 設計/AI/実行と費用/README.md 4 節の「完了と失敗の書き方」、データと失敗と検査.md 7 節)。
 * 終わった実行は変えず、失敗にしたかを返す(ジョブの failed() が、ExecutePrompt が失敗にした後にも呼ばれるため)。
 * 呼び手 = {@see FailRun} だけ(書き込みの失敗の報告も FailRun)。会話の型の実行は状態・失敗の種類と文・終わりの時刻だけを書く
 * (会話の型の使用量と費用はステップの行が持つ = docs の 設計/AI/実行の記録.md 2.1。実行の行は持てない)。
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

            $conversation = $run->kind_type === AiKindType::Conversation;

            if ($conversation && (($result->usage ?? []) !== [] || $result?->outputText !== null)) {
                report(new LogicException("会話の型の実行 {$runId} の失敗に、実行の行が持てない使用量か出力の文が渡された(書かない)"));
            }

            $run->fill([
                ...($conversation ? [] : ($result->usage ?? [])),
                'status' => AiRunStatus::Failed,
                'error_code' => $errorCode,
                'error_message' => mb_substr($exception?->getMessage() ?? '', 0, self::MESSAGE_MAX_CHARS),
                'finished_at' => now(),
            ])->save();

            if (! $conversation && $result?->outputText !== null) {
                AiRunText::query()->whereKey($runId)->update(['output_text' => $result->outputText]);
            }

            return true;
        });
    }
}

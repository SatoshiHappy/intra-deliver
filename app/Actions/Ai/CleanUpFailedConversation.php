<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStepStatus;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * 失敗で終わる会話の型の実行の後始末(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.8 の「失敗で終わるときの後始末」、守る結果 =
 * 設計/AI/実行と費用/README.md 4 節の「完了と失敗の書き方」の F1・F3)。失敗で終わった実行に処理中・確認待ちの子の行を残さないため、
 * 失敗を書く {@see MarkRunFailed} が実行の行の鍵の下で呼ぶ。
 */
class CleanUpFailedConversation
{
    public function __construct(private SumRunSteps $sums) {}

    /**
     * 子の行を書き換え、表示用の和を返す(呼び手が失敗と一緒に実行の行に書く)。前の実行の確認待ちを取り消すのは答えの実行だけ(答える対象を
     * 持つのは答えの実行 = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.8)。
     *
     * @param  AiRun  $run  失敗を埋めた会話の型の実行(鍵を取った行。終わりの時刻と失敗の種類を子の行に写す)
     * @return array{step_reserved_sum_usd: string, step_cost_sum_usd: string, step_usage_sum: array<string, int|float>}
     *
     * @throws LogicException 失敗で終わる会話の型の実行でないとき(作りの誤り)
     */
    public function execute(AiRun $run): array
    {
        if ($run->kind_type !== AiKindType::Conversation || $run->status !== AiRunStatus::Failed) {
            throw new LogicException("AI の実行 {$run->id} は失敗で終わる会話の型の実行でないので、後始末をしない");
        }

        $finishedAt = $run->finished_at;
        $awaitingOf = $run->answer === null ? [$run->id] : [$run->id, (int) $run->previous_run_id];

        AiToolCall::query()
            ->where('status', AiToolCallStatus::Running)
            ->where(fn (Builder $query) => $query->where('ai_run_id', $run->id)->orWhere('answer_run_id', $run->id))
            ->update(['status' => AiToolCallStatus::Unknown, 'finished_at' => $finishedAt]);
        AiToolCall::query()
            ->where('status', AiToolCallStatus::AwaitingConfirmation)
            ->whereIn('ai_run_id', $awaitingOf)
            ->update(['status' => AiToolCallStatus::Cancelled, 'finished_at' => $finishedAt]);
        AiRunStep::query()
            ->where('ai_run_id', $run->id)
            ->where('status', AiRunStepStatus::Running)
            ->update([
                'status' => AiRunStepStatus::Failed,
                'error_code' => $run->error_code,
                'finished_at' => $finishedAt,
            ]);

        return $this->sums->columns($run);
    }
}

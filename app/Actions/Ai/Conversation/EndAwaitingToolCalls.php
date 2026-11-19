<?php

namespace App\Actions\Ai\Conversation;

use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiToolCall;

/**
 * 止めたで終える時に確認待ちで残った道具の行を終える(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.5、状態 = 設計/AI/実行の記録.md
 * 2.4 の表)。答えの実行の答えは、書く前に止めても答えた件の行に残す(どの件に何と答えたかを読み取りで出すため)。
 */
final class EndAwaitingToolCalls
{
    /** @param  AiRun  $run  止めたで終える会話の型の実行 */
    public function execute(AiRun $run): void
    {
        $finished = ['finished_at' => now()];
        AiToolCall::query()
            ->where('ai_run_id', $run->id)
            ->where('status', AiToolCallStatus::AwaitingConfirmation)
            ->update([...$finished, 'status' => AiToolCallStatus::Cancelled]);

        if ($run->previous_run_id === null || $run->answer === null) {
            return;
        }

        $answered = $run->answer === AiConversationAnswer::Approve
            ? ['status' => AiToolCallStatus::Cancelled, 'answer' => AiToolCallAnswer::Approve]
            : ['status' => AiToolCallStatus::Rejected, 'answer' => AiToolCallAnswer::Reject];
        AiToolCall::query()
            ->where('ai_run_id', $run->previous_run_id)
            ->where('status', AiToolCallStatus::AwaitingConfirmation)
            ->update([...$finished, ...$answered, 'answer_run_id' => $run->id]);
    }
}

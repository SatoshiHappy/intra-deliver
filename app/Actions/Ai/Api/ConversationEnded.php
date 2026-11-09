<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use LogicException;

/**
 * 終わった会話の型の実行({@see ConversationKind::completed()} に渡す。docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「完了の受け手」)。
 * 答えの文は会話の保存にあるので渡さない。
 */
final readonly class ConversationEnded
{
    /**
     * @param  int  $runId  実行(ai_runs.id)
     * @param  string  $kindCode  種類のコード
     * @param  int  $requesterUserId  依頼者(users.id)
     * @param  AiRunStatus  $status  終わり方(成功・確認待ち・止めた)
     * @param  AiRunStopReason|null  $stopReason  止めた理由(止めたのときだけ)
     * @param  string|null  $title  会話の題(求めて作れたときだけ。① の規則に合うかは受け手が確かめる = 9.10)
     *
     * @throws LogicException 終わり方が 3 つのどれでもない・止めた理由の有無が終わり方と合わないとき(作りの誤り)
     */
    public function __construct(
        public int $runId,
        public string $kindCode,
        public int $requesterUserId,
        public AiRunStatus $status,
        public ?AiRunStopReason $stopReason,
        public ?string $title,
    ) {
        $ends = [AiRunStatus::Succeeded, AiRunStatus::AwaitingConfirmation, AiRunStatus::Stopped];

        if (! in_array($status, $ends, true)) {
            throw new LogicException("会話の型の実行 {$runId} の終わり方 {$status->value} は完了の受け手に渡せない");
        }

        if (($status === AiRunStatus::Stopped) !== ($stopReason !== null)) {
            throw new LogicException("会話の型の実行 {$runId} の止めた理由の有無が終わり方 {$status->value} と合わない");
        }
    }
}

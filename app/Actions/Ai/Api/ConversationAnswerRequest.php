<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiConversationAnswer;

/**
 * 会話の依頼(確認に答える。docs の 設計/AI/エージェントの実行/口と契約.md 9.12)の入力。前の実行は利用者が答えたカードを出した実行。
 */
final readonly class ConversationAnswerRequest
{
    public function __construct(
        public string $kindCode,
        public int $definitionId,
        public AiConversationAnswer $answer,
        public int $previousRunId,
    ) {}
}

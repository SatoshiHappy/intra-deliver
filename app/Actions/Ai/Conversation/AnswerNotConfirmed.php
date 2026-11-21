<?php

namespace App\Actions\Ai\Conversation;

use App\Enums\Ai\AiRunErrorCode;

/**
 * 答えの実行の始めの確かめが合わない(docs の 設計/AI/エージェントの実行/README.md 9.3 の「答えの実行の始めの確かめ」)。
 */
final class AnswerNotConfirmed extends ConversationStartFailure
{
    public function errorCode(): AiRunErrorCode
    {
        return AiRunErrorCode::Confirmation;
    }
}

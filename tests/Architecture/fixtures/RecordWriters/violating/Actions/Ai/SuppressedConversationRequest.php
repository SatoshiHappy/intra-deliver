<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\RequestConversation;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。Ai の内部も会話の依頼の口の呼び手の一覧の外
 */
final class SuppressedConversationRequest
{
    // @phpstan-ignore phpat.conversationRequestDependencies
    public function request(RequestConversation $request): RequestConversation
    {
        return $request;
    }
}

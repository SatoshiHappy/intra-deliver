<?php

namespace App\Http\Controllers\Web\Ai;

use App\Actions\Ai\Api\RequestConversation;

/**
 * 一覧に無い入口(会話の業務処理を通らない)からの会話の依頼の口への依存
 */
final class RequestsConversation
{
    public function __construct(private RequestConversation $request) {}

    public function request(): RequestConversation
    {
        return $this->request;
    }
}

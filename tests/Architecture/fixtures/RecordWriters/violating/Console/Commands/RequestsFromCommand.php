<?php

namespace App\Console\Commands;

use App\Actions\Ai\Api\RequestPrompt;

/**
 * 入口を持たない呼び手(コマンド)は依頼の口の一覧に足さない
 */
final class RequestsFromCommand
{
    public function __construct(private RequestPrompt $requestPrompt) {}

    public function requestPrompt(): RequestPrompt
    {
        return $this->requestPrompt;
    }
}

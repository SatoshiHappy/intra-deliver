<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\RequestPrompt;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。Ai の内部も依頼の口の呼び手の一覧の外
 */
final class SuppressedRequest
{
    // @phpstan-ignore phpat.runRequestDependencies
    public function requestPrompt(RequestPrompt $requestPrompt): RequestPrompt
    {
        return $requestPrompt;
    }
}

<?php

namespace Tests\Feature\Ai\Throttle;

use App\Actions\Ai\Api\RequestPrompt;

/**
 * 頻度の制限の付け忘れの検査の fixture: AI に依頼する業務処理(呼び手のモジュールの業務処理に当たる)。
 */
final class RequestingAction
{
    public function __construct(public readonly RequestPrompt $requestPrompt) {}
}

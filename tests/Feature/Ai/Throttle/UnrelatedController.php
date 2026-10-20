<?php

namespace Tests\Feature\Ai\Throttle;

/**
 * 頻度の制限の付け忘れの検査の fixture: RequestPrompt に届かない入口。
 */
final class UnrelatedController
{
    public function show(): string
    {
        return 'ok';
    }
}

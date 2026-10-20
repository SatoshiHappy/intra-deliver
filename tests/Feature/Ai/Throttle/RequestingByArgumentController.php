<?php

namespace Tests\Feature\Ai\Throttle;

/**
 * 頻度の制限の付け忘れの検査の fixture: 依頼の操作(store)は操作の引数の依存を 2 段たどると RequestPrompt に届き、
 * 状態の読み直しの操作(show)は届かない。
 */
final class RequestingByArgumentController
{
    public function store(RequestingAction $action): string
    {
        return $action::class;
    }

    public function show(): string
    {
        return 'queued';
    }
}

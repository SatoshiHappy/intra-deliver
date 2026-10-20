<?php

namespace Tests\Feature\Ai\Throttle;

/**
 * 頻度の制限の付け忘れの検査の fixture: 構築子の依存を 2 段たどると RequestPrompt に届くコントローラ(コントローラ → 業務処理 → RequestPrompt)。
 * 依頼の業務処理を構築子で受けるので、それだけで違反。
 */
final class RequestingController
{
    public function __construct(private readonly RequestingAction $action) {}

    public function store(): string
    {
        return $this->action::class;
    }
}

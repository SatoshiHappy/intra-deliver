<?php

namespace App\Actions\Notice;

use App\Models\Notice\Notice;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * 自分宛の未読の通知をすべて既読にする(「すべて既読にする」= docs の 設計/ワークフロー/案件の運用/通知.md の 6 節)。ほかの人の行は変えない。
 */
class MarkAllNoticesRead
{
    /**
     * @throws AuthorizationException ゲストのとき(403)
     */
    public function execute(AccessContext $context): void
    {
        $userId = $context->userId ?? throw new AuthorizationException(MarkNoticeRead::GUEST);

        Notice::query()->where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);
    }
}

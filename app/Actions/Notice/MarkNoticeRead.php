<?php

namespace App\Actions\Notice;

use App\Models\Notice\Notice;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 通知の行を既読にする(行を押した時 = docs の 設計/ワークフロー/案件の運用/通知.md の 6 節)。判定を求める者のユーザー宛の行だけを書く。
 * 既読の行は既読の日時を変えない(既読を未読に戻す操作は無い)。
 */
class MarkNoticeRead
{
    public const GUEST = 'ゲストは通知を既読にできません';

    /**
     * @throws AuthorizationException ゲストのとき(403)
     * @throws ModelNotFoundException<Notice> 行が無い・宛先が自分でないとき(404。存在を見せない)
     */
    public function execute(AccessContext $context, int $noticeId): void
    {
        $userId = $context->userId ?? throw new AuthorizationException(self::GUEST);
        $notice = Notice::query()->whereKey($noticeId)->where('user_id', $userId)->first()
            ?? throw (new ModelNotFoundException)->setModel(Notice::class, [$noticeId]);

        if ($notice->read_at === null) {
            $notice->update(['read_at' => now()]);
        }
    }
}

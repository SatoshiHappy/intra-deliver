<?php

namespace Tests\Support;

use App\Models\Notice\Notice;

/**
 * 初期データの通知の行を、テストの TX の中で消してから始める手助け(初期データは案件の操作の業務処理を通すので通知の行を持つ =
 * docs の 設計/ワークフロー/案件の運用/通知.md の 4 節)。自分宛の一覧・未読の数・並びを、テストが作った行だけで確かめるため。
 * 消した行はテストの終わりの巻き戻しで戻る。
 */
trait StartsWithoutSeededNotices
{
    protected function setUpStartsWithoutSeededNotices(): void
    {
        Notice::query()->delete();
    }
}

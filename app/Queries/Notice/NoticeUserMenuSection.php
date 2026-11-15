<?php

namespace App\Queries\Notice;

use App\Queries\Account\Api\UserMenuSections;
use App\Support\AccessContext;

/**
 * 利用者メニューの「通知」の節(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.1、ADR 0045 の決定 5)。
 * 項目は「通知の設定」1 つで、ゲストには項目の無い節(設定するものが無い = 3.4)。起動がタグ {@see UserMenuSections::TAG} で登録する。
 */
class NoticeUserMenuSection implements UserMenuSections
{
    public function section(AccessContext $context): array
    {
        if ($context->userId === null) {
            return ['heading' => '通知', 'items' => []];
        }

        return [
            'heading' => '通知',
            'items' => [[
                'code' => 'notice-settings',
                'name' => '通知の設定',
                'note' => null,
                'url' => route('notice.settings.show', [], false),
                'method' => 'get',
                'data' => [],
            ]],
        ];
    }
}

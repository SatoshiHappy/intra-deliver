<?php

namespace App\Queries\Workflow;

use App\Queries\Account\Api\UserMenuSections;
use App\Support\AccessContext;

/**
 * 利用者メニューの「ワークフロー」の節(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 5 節)。項目は「代理の設定」1 つで、
 * ゲストには項目の無い節(画面は出さない = 口の約束)。起動(App\Providers)がタグ {@see UserMenuSections::TAG} で登録する。
 */
class WorkflowUserMenuSection implements UserMenuSections
{
    public function section(AccessContext $context): array
    {
        if ($context->userId === null) {
            return ['heading' => 'ワークフロー', 'items' => []];
        }

        return [
            'heading' => 'ワークフロー',
            'items' => [[
                'code' => 'workflow-proxies',
                'name' => '代理の設定',
                'note' => null,
                'url' => route('workflow.proxies.index', [], false),
                'method' => 'get',
                'data' => [],
            ]],
        ];
    }
}

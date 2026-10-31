<?php

namespace App\Queries\Workflow;

use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;

/**
 * ワークフローのホームのあいさつ欄の一文(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節。口 = {@see HomeSections}、形 = 設計/メニュー/ホーム.md 6.2)。
 * 「未処理の申請は<未処理の件数>件、進行中の申請は<進行中の申請の件数>件です。」。ゲストには案内の一文「ゲストは申請も処理もできません。」。主ボタンは無い。
 *
 * @phpstan-import-type HomeLeadSection from HomeSections
 */
class WorkflowLeadHomeSection implements HomeSections
{
    public function __construct(private WorkflowHomeQuery $home) {}

    /**
     * @return HomeLeadSection|null
     */
    public function section(AccessContext $context): ?array
    {
        if ($this->home->isGuest($context)) {
            $text = 'ゲストは申請も処理もできません。';
        } else {
            $pending = $this->home->pending($context)->count();
            $active = $this->home->activeApplications($context)->count();
            $text = "未処理の申請は{$pending}件、進行中の申請は{$active}件です。";
        }

        return [
            'code' => 'workflow-lead',
            'kind' => 'lead',
            'area' => 'hero',
            'text' => $text,
            'action' => null,
        ];
    }
}

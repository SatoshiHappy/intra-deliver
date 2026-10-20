<?php

namespace App\Queries\Workflow;

use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;

/**
 * ホームの件数カード(docs の 設計/ワークフロー/画面.md 7 節。口 = {@see HomeSections}、形 = 設計/メニュー/ホーム.md 6.2)。4 枚 = 未処理の申請・
 * 進行中の申請・今月の処理済み・一時保存(この順)。内訳は 0 の語を出さず「・」で繋ぐ(全部 0 なら null。一時保存は内訳なし)。
 * 行き先はその案件が入る案件一覧のタブ(ルートの名前から作る)。ゲストには null、件数が 0 でも出す。
 *
 * @phpstan-import-type HomeCountersSection from HomeSections
 */
class WorkflowCountersHomeSection implements HomeSections
{
    public function __construct(private WorkflowHomeQuery $home) {}

    /**
     * @return HomeCountersSection|null
     */
    public function section(AccessContext $context): ?array
    {
        if ($this->home->isGuest($context)) {
            return null;
        }

        $pending = $this->home->pending($context);
        $active = $this->home->activeApplications($context);
        $processed = $this->home->processedThisMonth($context);

        return [
            'code' => 'workflow-counters',
            'kind' => 'counters',
            'area' => 'top',
            'heading' => '件数',
            'items' => [
                $this->item('pending', '未処理の申請', 'inbox', $pending->count(), WorkflowHomeLabels::note(WorkflowHomeLabels::CURRENT_NODE_STATES, $this->home->countsByLabelState($pending)), 'pending'),
                $this->item('active-applications', '進行中の申請', 'send', $active->count(), WorkflowHomeLabels::note(WorkflowHomeLabels::CURRENT_NODE_STATES, $this->home->countsByLabelState($active)), 'processed'),
                $this->item('processed-this-month', '今月の処理済み', 'folder', $processed->count(), WorkflowHomeLabels::note(WorkflowHomeLabels::RESULTS, $this->home->countsByAction($processed)), 'processed'),
                $this->item('draft', '一時保存', 'file-text', $this->home->drafts($context)->count(), null, 'draft'),
            ],
        ];
    }

    /**
     * @return array{code: string, label: string, icon: string, count: int, note: string|null, url: string}
     */
    private function item(string $code, string $label, string $icon, int $count, ?string $note, string $tab): array
    {
        return ['code' => $code, 'label' => $label, 'icon' => $icon, 'count' => $count, 'note' => $note, 'url' => route('workflow.cases.index', ['tab' => $tab])];
    }
}

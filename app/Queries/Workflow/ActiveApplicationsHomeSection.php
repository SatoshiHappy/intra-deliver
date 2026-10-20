<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * ホームの進行中の申請の経路(docs の 設計/ワークフロー/画面.md 7 節。口 = {@see HomeSections}、形 = 設計/メニュー/ホーム.md 6.2)。進行中の申請の
 * 先頭の 3 件。項目 = 申請名・詳細・「<案件の札の語>：<現在のステップの表示名を「、」で並べたもの>」・写したノード(展開の行を除く)を位置の順に全部、
 * 各ノードの状態どおり(ステップの表 = ルートの深さ/認可と一覧.md 2 節の H2・H3)・操作(行の can.reapply が真なら「再申請」= 再申請の画面。一覧の行と同じ判定
 * {@see CaseRows})。ゲストには null、0 件でも出す(0 件の文)。
 *
 * @phpstan-import-type HomeRoutesSection from HomeSections
 * @phpstan-import-type CaseRow from CaseRows
 */
class ActiveApplicationsHomeSection implements HomeSections
{
    /** 出す項目の数 */
    public const LIMIT = 3;

    public function __construct(
        private WorkflowHomeQuery $home,
        private CaseRows $rows,
    ) {}

    /**
     * @return HomeRoutesSection|null
     */
    public function section(AccessContext $context): ?array
    {
        if ($this->home->isGuest($context)) {
            return null;
        }

        $cases = $this->home->activeApplications($context)->limit(self::LIMIT)->get();
        $nodes = WorkflowCaseNode::query()
            ->whereIn('workflow_case_id', array_values($cases->map(fn (WorkflowCase $case) => $case->id)->all()))
            ->whereNull('parent_workflow_case_node_id')
            ->orderBy('position')
            ->get()
            ->groupBy('workflow_case_id');

        return [
            'code' => 'workflow-active-applications',
            'kind' => 'routes',
            'area' => 'side',
            'heading' => '進行中の申請',
            'items' => array_map(fn (array $row) => $this->item($row, $nodes->get($row['id']) ?? new Collection), $this->rows->rows(Requester::fromAccessContext($context), $context->date, $context->localeCode, $cases)),
            'emptyText' => '進行中の申請はありません',
            'more' => ['label' => '申請一覧', 'url' => route('workflow.cases.index', ['tab' => 'processed'])],
        ];
    }

    /**
     * @param  CaseRow  $row
     * @param  Collection<int, WorkflowCaseNode>  $nodes  案件の写したノード(展開の行を除く。位置の順)
     * @return HomeRoutesSection['items'][int]
     */
    private function item(array $row, Collection $nodes): array
    {
        $current = $row['currentSummary'];

        return [
            'code' => (string) $row['id'],
            'title' => $row['name'],
            'url' => route('workflow.cases.show', $row['id']),
            'note' => $current === null ? '' : (WorkflowHomeLabels::CURRENT_NODE_STATES[$current['state']][0] ?? '').'：'.$current['name'],
            'steps' => array_values($nodes->map($this->step(...))->all()),
            'action' => $row['can']['reapply'] ? ['label' => '再申請', 'url' => route('workflow.reapplications.create', $row['id']), 'method' => 'get', 'data' => []] : null,
        ];
    }

    /**
     * ステップ 1 つ(ノードの状態どおり = ルートの深さ/認可と一覧.md 2 節の H2。段 2 の「現在のノードより後ろの位置は到達していない」の規則は外した。
     * 差戻しは差戻し先と子孫を到達していないに戻すので、差戻しの後の絵は段 2 と同じ)。
     *
     * @return HomeRoutesSection['items'][int]['steps'][int]
     */
    private function step(WorkflowCaseNode $node): array
    {
        [$state, $label] = WorkflowHomeLabels::step($node->state);

        return ['code' => (string) $node->id, 'label' => $node->name, 'state' => $state, 'stateLabel' => $label];
    }
}

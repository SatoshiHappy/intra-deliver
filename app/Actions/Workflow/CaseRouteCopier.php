<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseSendBack;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;

/**
 * 申請の写し(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.2〜3.4): 版のノード(状態はすべて到達していない)・辺(taken は NULL)・
 * 差戻しできる先を案件へ全部写す。ノードは参照される側(対の終了・指定するノード)を先に書き({@see ReferenceFirstOrder})、指す列は写した案件のノードを
 * 最初から指す(NULL で入れて指し直すと遅延できない検査制約に当たる)。定義の表は鍵を取らずに読む。呼び手のトランザクションの中で書く。
 * 期限の日数・確認する人も同じ写しの列(設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節)。添付を追加できないも同じ
 * (設計/ワークフロー/申請の周辺/データモデル.md の 3.1 節)。
 */
class CaseRouteCopier
{
    /** ルートのノードから案件のノードへそのまま写す列(同じ版のノードを指す列は写したノードへ付け替える) */
    private const COPIED_COLUMNS = [
        'position', 'code', 'name', 'kind', 'target_kind', 'authz_subject_group_id', 'relative_base', 'relative_scope', 'company_post_id',
        'min_assignees', 'max_assignees', 'decision_rule', 'decision_value', 'prevents_applicant_approval',
        'deadline_days', 'confirm_authz_subject_group_id', 'prevents_attachment_add',
    ];

    public function __construct(private ReferenceFirstOrder $order) {}

    /**
     * 版のルートを全部写す。
     *
     * @return array<int, WorkflowCaseNode> ルートのノードの id → 写した案件のノード(版のノードの position の順)
     */
    public function copy(WorkflowCase $case, int $versionId): array
    {
        $nodes = $this->copyNodes($case, array_values(WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get()->all()));

        foreach (WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get() as $edge) {
            WorkflowCaseEdge::query()->create([
                'workflow_case_id' => $case->id, 'from_workflow_case_node_id' => $nodes[$edge->from_workflow_route_node_id]->id, 'to_workflow_case_node_id' => $nodes[$edge->to_workflow_route_node_id]->id,
                'sort_key' => $edge->sort_key, 'label' => $edge->label, 'condition' => $edge->condition, 'taken' => null,
            ]);
        }

        foreach (WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get() as $sendBack) {
            WorkflowCaseSendBack::query()->create([
                'workflow_case_id' => $case->id, 'workflow_case_node_id' => $nodes[$sendBack->workflow_route_node_id]->id, 'target_workflow_case_node_id' => $nodes[$sendBack->target_workflow_route_node_id]->id,
            ]);
        }

        return $nodes;
    }

    /**
     * ルートのノードを写す(参照される側から)。
     *
     * @param  list<WorkflowRouteNode>  $routeNodes  同じ版のノード全部
     * @return array<int, WorkflowCaseNode> ルートのノードの id → 写した案件のノード(渡した並びの順。書く順は参照される側から)
     */
    public function copyNodes(WorkflowCase $case, array $routeNodes): array
    {
        $byId = [];

        foreach ($routeNodes as $routeNode) {
            $byId[$routeNode->id] = $routeNode;
        }

        $references = array_map(fn (WorkflowRouteNode $node) => array_values(array_filter([$node->closing_workflow_route_node_id, $node->designator_workflow_route_node_id], fn (?int $id) => $id !== null)), $byId);
        $copied = [];

        foreach ($this->order->order($references) as $routeNodeId) {
            $routeNode = $byId[$routeNodeId];
            $copied[$routeNodeId] = WorkflowCaseNode::query()->create([
                'workflow_case_id' => $case->id,
                ...$routeNode->only(self::COPIED_COLUMNS),
                'designator_workflow_case_node_id' => $routeNode->designator_workflow_route_node_id === null ? null : $copied[$routeNode->designator_workflow_route_node_id]->id,
                'closing_workflow_case_node_id' => $routeNode->closing_workflow_route_node_id === null ? null : $copied[$routeNode->closing_workflow_route_node_id]->id,
                'state' => WorkflowNodeState::Unreached,
                'workflow_case_history_id' => null,
            ]);
        }

        return array_map(fn (WorkflowRouteNode $routeNode) => $copied[$routeNode->id], $byId);
    }
}

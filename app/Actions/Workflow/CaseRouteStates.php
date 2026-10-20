<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Queries\Workflow\CurrentAssignments;
use App\Queries\Workflow\NodeTargetExpansion;
use App\Queries\Workflow\RouteGraph;
use LogicException;

/**
 * 計画の作業の状態({@see CaseRouteState})を、案件の今の行から読む(ノード・辺・処理対象者・処理済みのノードの履歴の種別・今の指定・前処理者の起点の組織)。
 * 呼び手が案件の行に鍵を取った後に呼ぶ。問い合わせはノードの数に依らない。
 */
class CaseRouteStates
{
    public function __construct(
        private CurrentAssignments $assignments,
        private NodeTargetExpansion $expansion,
    ) {}

    public function load(CasePlanRequest $request): CaseRouteState
    {
        $case = $request->case;
        $baseDate = $case->base_date ?? throw new LogicException("案件 {$case->id} に申請基準日が無い(一時保存は計画しない)");
        $nodes = WorkflowCaseNode::query()->with('parent')->where('workflow_case_id', $case->id)->orderBy('id')->get()->keyBy('id')->all();
        $edges = WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->orderBy('sort_key')->orderBy('id')->get();
        $routeNodeIds = array_values(array_map(fn (WorkflowCaseNode $node) => $node->id, array_filter($nodes, fn (WorkflowCaseNode $node) => $node->parent_workflow_case_node_id === null)));

        if (count($routeNodeIds) > 1 && $edges->isEmpty()) {
            throw new LogicException("案件 {$case->id} はノードが 2 つ以上あるのに辺を写していない(申請の時に辺を写していなかった前の形の申請。最初の承認で黙って完了させない)");
        }

        $targets = WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', array_keys($nodes))->orderBy('user_id')->get()->groupBy('workflow_case_node_id')
            ->map(fn ($rows) => array_values(array_map(fn (WorkflowCaseNodeTarget $target) => (int) $target->user_id, $rows->all())))->all();
        $historyIds = array_values(array_filter(array_map(fn (WorkflowCaseNode $node) => $node->workflow_case_history_id, $nodes)));
        $historyActions = WorkflowCaseHistory::query()->whereIn('id', $historyIds)->get()->mapWithKeys(fn (WorkflowCaseHistory $history) => [$history->id => $history->action])->all();

        return new CaseRouteState(
            case: $case,
            nodes: $nodes,
            edges: new CaseEdgeState(array_map(fn ($group) => array_values($group->all()), $edges->groupBy('from_workflow_case_node_id')->all())),
            graph: RouteGraph::of($routeNodeIds, array_values($edges->map(fn (WorkflowCaseEdge $edge) => [$edge->from_workflow_case_node_id, $edge->to_workflow_case_node_id])->all())),
            targets: $targets,
            historyActions: $historyActions,
            assignments: array_replace($this->assignments->of($case->id), $request->assignments),
            previousDepartmentId: $this->expansion->originDepartmentOn($request->previousUserId, $request->selectedDepartmentId, $baseDate),
            baseDate: $baseDate,
        );
    }
}

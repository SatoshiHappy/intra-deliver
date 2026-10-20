<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseSendBack;

/**
 * 差戻しの候補(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.4。画面と業務処理が同じ規則で出す 1 か所): 操作するノード N(展開の行なら親)の
 * 祖先の候補 A(申請・承認(展開の行でない)・横配置・縦配置で、処理済みか通過)と、N の差戻しできる先 L の積。L が空なら候補は無い。A ∩ L が空なら A
 * (本家 RB-2)。並び = 写した並び(position)。鍵を取らない。
 */
class SendBackCandidates
{
    /**
     * @param  int  $nodeId  操作するノード(同じ案件のノード。展開の行なら親で読む)
     * @return list<WorkflowCaseNode> 候補(position の順。展開の行は入らない)
     */
    public function of(int $nodeId): array
    {
        return $this->withRestriction($nodeId)['candidates'];
    }

    /**
     * 候補と、差戻しできる先の制限が効いているか(A ∩ L が空でなく A より狭い = 画面の制限の 1 行 = ルートの深さ/画面.md 6 節。RB-2 で A になったときは偽)。
     *
     * @param  int  $nodeId  操作するノード(同じ案件のノード。展開の行なら親で読む)
     * @return array{candidates: list<WorkflowCaseNode>, isRestricted: bool}
     */
    public function withRestriction(int $nodeId): array
    {
        $operated = WorkflowCaseNode::query()->findOrFail($nodeId);
        $originId = $operated->parent_workflow_case_node_id ?? $operated->id;
        $routeNodes = WorkflowCaseNode::query()->where('workflow_case_id', $operated->workflow_case_id)->whereNull('parent_workflow_case_node_id')->orderBy('position')->get()->keyBy('id');
        $edges = WorkflowCaseEdge::query()->where('workflow_case_id', $operated->workflow_case_id)->get();
        $graph = RouteGraph::of(array_keys($routeNodes->all()), array_values($edges->map(fn (WorkflowCaseEdge $edge) => [$edge->from_workflow_case_node_id, $edge->to_workflow_case_node_id])->all()));
        $allowed = WorkflowCaseSendBack::query()->where('workflow_case_node_id', $originId)->pluck('target_workflow_case_node_id')->map(fn (mixed $id) => (int) $id)->all();

        if ($allowed === []) {
            return ['candidates' => [], 'isRestricted' => false];
        }

        $ancestorIds = $graph->ancestorsOf($originId);
        $ancestors = $routeNodes->filter(fn (WorkflowCaseNode $node) => in_array($node->id, $ancestorIds, true) && $node->kind->isIn(WorkflowNodeKind::targeted()) && in_array($node->state, WorkflowNodeState::traversed(), true));
        $restricted = $ancestors->filter(fn (WorkflowCaseNode $node) => in_array($node->id, $allowed, true));

        $isRestricted = $restricted->isNotEmpty() && $restricted->count() < $ancestors->count();

        return ['candidates' => array_values(($restricted->isEmpty() ? $ancestors : $restricted)->all()), 'isRestricted' => $isRestricted];
    }
}

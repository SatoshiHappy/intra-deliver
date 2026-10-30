<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;

/**
 * 指定を変更できるノード(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.2 節・15.6 節)と、1 つのノードがそれに当たるかの判定。
 * 業務処理(指定の変更)と詳細の申請操作の面が同じ式を使う。鍵を取らない。
 */
class ReassignCandidates
{
    public function __construct(private DesignatorNodeGuard $designatorGuard) {}

    /**
     * 指定の変更の前提: Y が処理の時に指定のノードで到達していない、Y の指定するノード X が処理済み、Y がほかのノードの指定するノードでない。
     */
    public function isReassignable(WorkflowCaseNode $node): bool
    {
        $designatorId = $node->designator_workflow_case_node_id;
        $designator = $designatorId === null ? null : WorkflowCaseNode::query()->find($designatorId);

        return $node->state === WorkflowNodeState::Unreached
            && $designator?->state === WorkflowNodeState::Done
            && ! $this->designatorGuard->designatesAnother($node->id);
    }

    /**
     * 案件の、指定を変更できるノード(写した並び)。前提は {@see self::isReassignable()} で見る(問い合わせの絞りは候補を減らすだけ)。
     *
     * @return list<WorkflowCaseNode>
     */
    public function reassignableNodesOf(int $caseId): array
    {
        $candidates = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)
            ->whereNotNull('designator_workflow_case_node_id')->where('state', WorkflowNodeState::Unreached)->get()
            ->sort(WorkflowCaseNode::compareInRoute(...))->values();

        return array_values(array_filter($candidates->all(), $this->isReassignable(...)));
    }
}

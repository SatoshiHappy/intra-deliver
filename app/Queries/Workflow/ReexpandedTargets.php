<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use LogicException;

/**
 * 再展開で展開し直す人を、適用せずに求める 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.4 節・15.1 節の 1)。
 * 再展開の業務処理(0 人・申請者だけ・ほかの子の人の拒否を足す)と、詳細の申請操作の面の「展開後の処理担当者」が同じ式を使う。鍵を取らない。
 */
class ReexpandedTargets
{
    public function __construct(
        private NodeTargetExpansion $expansion,
        private CurrentAssignments $assignments,
    ) {}

    /**
     * 展開の 1 か所({@see NodeTargetExpansion})か今の指定で、申請基準日の人を求める(指定の人に申請基準日に無効な人がいれば 0 人と同じに扱う =
     * 前のノードからの到達と同じ規則)。
     *
     * @return list<int> 展開し直した人(0 人なら空)
     *
     * @throws LogicException 案件が申請基準日を持たないとき(一時保存でない案件は持つ)
     */
    public function of(WorkflowCase $case, WorkflowCaseNode $node): array
    {
        $baseDate = $case->base_date ?? throw new LogicException("未完了の案件 {$case->id} に申請基準日が無い");
        $parentId = $node->parent_workflow_case_node_id;

        if ($parentId === null && $node->target_kind !== WorkflowTargetKind::Designated) {
            $origin = $this->expansion->recordedOriginOf($case, $node, $baseDate);

            return $this->expansion->expand($node, $case->department_id, $origin, $baseDate);
        }

        $assigned = $this->assignments->of($case->id)[$parentId ?? $node->id] ?? [];
        $userIds = $parentId === null ? $assigned : array_slice($assigned, (int) $node->sequence - 1, 1);

        return $this->expansion->allEffectiveOn($userIds, $baseDate) ? $userIds : [];
    }
}

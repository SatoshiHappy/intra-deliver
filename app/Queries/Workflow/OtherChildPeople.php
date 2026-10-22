<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;

/**
 * 配置の子の「同じ親のほかの子の人」の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節の規則 (4)。振替先・変更後の
 * 処理対象者・再展開の結果が同じ読みを使う)。ほかの子ごとに今の人と今の指定のその番号の人の両方を返す(配置の親へ戻すと、子は処理した人か今の
 * 指定の人で到達し直すため)。進むで処理済みにした子の今の人は今の指定の人(案件操作の処理者を数えない = 設計/ワークフロー/案件の運用/案件の操作/
 * 案件操作.md の 15.1 節)。処理済み・到達していない子を含むので DB から読む。鍵を取らない(案件の行の鍵の下で呼ぶ)。
 */
class OtherChildPeople
{
    public function __construct(private CurrentAssignments $assignments) {}

    /**
     * @return list<int> ほかの子の人のユーザーの id(重ねない。配置の子でなければ空)
     */
    public function of(WorkflowCaseNode $node): array
    {
        $parentId = $node->parent_workflow_case_node_id;

        if ($parentId === null) {
            return [];
        }

        $siblings = WorkflowCaseNode::query()->with('workflowCaseHistory')
            ->where('parent_workflow_case_node_id', $parentId)->whereKeyNot($node->id)->get();
        $targets = WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', $siblings->modelKeys())->get()
            ->groupBy('workflow_case_node_id');
        $assigned = $this->assignments->of($node->workflow_case_id)[$parentId] ?? [];
        $people = [];

        foreach ($siblings as $sibling) {
            $assignedPerson = array_slice($assigned, (int) $sibling->sequence - 1, 1);
            $doneBy = $sibling->state === WorkflowNodeState::Done ? $sibling->workflowCaseHistory : null;
            $current = match (true) {
                $doneBy?->action === WorkflowAction::MoveForward => [],
                $doneBy !== null => [$doneBy->user_id],
                $sibling->state->isCurrent() => array_map(
                    fn (WorkflowCaseNodeTarget $row) => (int) $row->user_id,
                    $targets->get($sibling->id)?->all() ?? [],
                ),
                default => [],
            };
            $people = [...$people, ...$current, ...$assignedPerson];
        }

        return array_values(array_unique($people));
    }
}

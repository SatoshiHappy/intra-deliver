<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;

/**
 * 計画を書く(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節の 2・5): 新しい展開の行を先に足して id を得る({@see self::addChildren()}。
 * 履歴の行の node_changes に入れるため)→(呼び手が履歴の行・指定の行を書く)→ ノードを書く順に動かし、分岐開始の辺の taken を書く({@see self::apply()})。
 * 処理対象者の行は、変えるノードの分を先に全部消してから入れる。到達日時と前処理者はノードの状態より先に書く({@see CaseReachWriter})。
 * 案件の行は呼び手が書く。呼び手のトランザクションの中で書く。
 */
class CaseRoutePlanWriter
{
    public function __construct(private CaseReachWriter $reaches) {}

    /** 展開の行を足すときに書く列 */
    private const CHILD_COLUMNS = [
        'workflow_case_id', 'name', 'kind', 'parent_workflow_case_node_id', 'sequence', 'prevents_applicant_approval',
        'state', 'deadline_days',
    ];

    /**
     * 新しい展開の行を足す(状態 = 到達していない)。
     *
     * @return array<int, int> 仮の id → 書いた行の id
     */
    public function addChildren(CaseRoutePlan $plan): array
    {
        $ids = [];

        foreach ($plan->newChildren as $temporaryId => $child) {
            $ids[$temporaryId] = WorkflowCaseNode::query()->create($child->only(self::CHILD_COLUMNS))->id;
        }

        return $ids;
    }

    /**
     * ノードを書く順に動かす(操作するノードは $historyId を指す処理済み)。
     *
     * @param  array<int, int>  $ids  仮の id → 書いた行の id({@see self::addChildren()})
     * @param  int  $historyId  この操作の履歴の行
     */
    public function apply(CaseRoutePlan $plan, array $ids, int $historyId): void
    {
        $order = $plan->writeOrder();
        $this->reaches->write($plan, $ids);
        WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', array_map(fn (int $id) => $ids[$id] ?? $id, $order))->delete();
        $now = now();
        $targets = [];

        foreach ($order as $id) {
            $values = $plan->after[$id];
            $nodeId = $ids[$id] ?? $id;
            $history = $id === $plan->processedNodeId ? $historyId : $values['history'];
            WorkflowCaseNode::query()->whereKey($nodeId)->update(['state' => $values['state']->value, 'workflow_case_history_id' => $values['state'] === WorkflowNodeState::Done ? $history : null]);

            foreach ($values['targets'] as $userId) {
                $targets[] = ['workflow_case_node_id' => $nodeId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        WorkflowCaseNodeTarget::query()->insert($targets);

        foreach ($plan->taken as $edges) {
            foreach ($edges as $edgeId => [, $taken]) {
                WorkflowCaseEdge::query()->whereKey($edgeId)->update(['taken' => $taken]);
            }
        }
    }
}

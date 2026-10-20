<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;

/**
 * 「以前そのノードを処理した人」の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4.5。差戻し先と、配置の親へ差戻したときの子の戻す相手):
 * 今そのノード(展開の行ならその行)が処理済みで指している履歴の行(workflow_case_history_id。合議の判定が数える行と同じ)の処理者。前の回の履歴は見ない。
 * 処理済みでないノードは入れない(呼び手が今の指定の同じ番号の人にする)。差戻しの計画が書く前に呼ぶ(= 差戻しの直前)。鍵を取らない。
 */
class PreviousProcessors
{
    /**
     * @param  list<int>  $nodeIds  案件のノードの id
     * @return array<int, int> 処理済みのノードの id → 処理者のユーザーの id
     */
    public function of(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $histories = WorkflowCaseNode::query()->whereKey($nodeIds)->where('state', WorkflowNodeState::Done)->whereNotNull('workflow_case_history_id')->pluck('workflow_case_history_id', 'id');
        $users = WorkflowCaseHistory::query()->whereKey($histories->values()->all())->pluck('user_id', 'id');

        return $histories->map(fn (mixed $historyId) => (int) $users[$historyId])->all();
    }
}

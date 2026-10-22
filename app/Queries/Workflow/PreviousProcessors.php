<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use LogicException;

/**
 * 「以前そのノードを処理した人」の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4.5。差戻し先と、配置の親へ差戻したときの子の戻す相手):
 * 今そのノード(展開の行ならその行)が処理済みで指している履歴の行(workflow_case_history_id。合議の判定が数える行と同じ)の処理者。前の回の履歴は見ない。
 * 処理済みにした行が進むなら null(案件操作の処理者を数えず、以前に処理していないのと同じに扱う = 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の
 * 15.1 節の 2)。処理済みでないノードは入れない(呼び手が今の指定の同じ番号の人にする)。差戻しの計画が書く前に呼ぶ(= 差戻しの直前)。鍵を取らない。
 */
class PreviousProcessors
{
    /**
     * @param  list<int>  $nodeIds  案件のノードの id
     * @return array<int, int|null> 処理済みのノードの id → 処理者のユーザーの id(進むで処理済みにしたノードは null)
     */
    public function of(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $histories = WorkflowCaseNode::query()->whereKey($nodeIds)->where('state', WorkflowNodeState::Done)->whereNotNull('workflow_case_history_id')->pluck('workflow_case_history_id', 'id');
        $rows = WorkflowCaseHistory::query()->whereKey($histories->values()->all())->get()->keyBy('id');

        return $histories->map(function (mixed $historyId) use ($rows): ?int {
            $row = $rows->get($historyId) ?? throw new LogicException("処理済みのノードが指す履歴の行 {$historyId} が無い");

            return $row->action === WorkflowAction::MoveForward ? null : $row->user_id;
        })->all();
    }
}

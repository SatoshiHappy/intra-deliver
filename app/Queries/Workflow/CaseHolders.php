<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCaseHistory;

/**
 * 保留者側を決める「最新の保留の行の処理者」の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節の保留者側)。
 * 問い合わせは渡したノードの数に依らず 1 回。鍵を取らない。
 */
class CaseHolders
{
    /**
     * @param  list<int>  $nodeIds  保留中のノード
     * @return array<int, int> ノードの id → そのノードの最新の保留の行の処理者(保留の行の無いノードは入れない)
     */
    public function of(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $latest = WorkflowCaseHistory::query()->whereIn('workflow_case_node_id', $nodeIds)
            ->where('action', WorkflowAction::Hold)->groupBy('workflow_case_node_id')->selectRaw('max(id)');
        $rows = WorkflowCaseHistory::query()->whereIn('id', $latest)->get(['workflow_case_node_id', 'user_id']);
        $holders = [];

        foreach ($rows as $row) {
            $holders[(int) $row->workflow_case_node_id] = (int) $row->user_id;
        }

        return $holders;
    }
}

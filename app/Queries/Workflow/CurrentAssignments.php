<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Builder;

/**
 * 今の指定の 1 か所(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.5): 指定されたノード Y の指定するノード X の、引戻されていない最新の承認の
 * 履歴の行(引戻しの行が指していない X の承認の行のうち id の最大)の、Y の指定の行の集まり(sequence の順)。行が 0 = 0 人を指定した。
 * 計画の中でこの操作の検証済みの指定で上書きするのは呼び手(計画)。承認のダイアログの初期値は引戻された行も含めた最新の承認の指定({@see self::latestOf()}。
 * 案件の操作/処理対象者.md 5.2、通しの回の T07)。鍵を取らない。
 */
class CurrentAssignments
{
    /**
     * 案件の、指定されたノードごとの今の指定。X の承認の行が無い Y は入れない(X は申請から Y へのどの経路にも通る = Y に到達する前に X が承認している)。
     *
     * @return array<int, list<int>> Y の id(id の順)→ 指定した人のユーザーの id(指定の順)
     */
    public function of(int $caseId): array
    {
        return $this->assignmentsOf($caseId, false);
    }

    /**
     * 承認のダイアログの指定の初期値: X の承認の行のうち id の最大(引戻されたかを問わない)の指定。形は {@see self::of()} と同じ。
     *
     * @return array<int, list<int>> Y の id(id の順)→ 指定した人のユーザーの id(指定の順)
     */
    public function latestOf(int $caseId): array
    {
        return $this->assignmentsOf($caseId, true);
    }

    /**
     * @return array<int, list<int>>
     */
    private function assignmentsOf(int $caseId, bool $includesPulledBack): array
    {
        $designators = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->whereNotNull('designator_workflow_case_node_id')
            ->orderBy('id')->pluck('designator_workflow_case_node_id', 'id')->map(fn (mixed $id) => (int) $id)->all();

        if ($designators === []) {
            return [];
        }

        $reverted = WorkflowCaseHistory::query()->where('workflow_case_id', $caseId)->whereNotNull('reverted_workflow_case_history_id')->select('reverted_workflow_case_history_id');
        $latestApprovals = WorkflowCaseHistory::query()
            ->where('workflow_case_id', $caseId)
            ->whereIn('workflow_case_node_id', array_values(array_unique($designators)))
            ->where('action', WorkflowAction::Approve)
            ->when(! $includesPulledBack, fn (Builder $query) => $query->whereNotIn('id', $reverted))
            ->groupBy('workflow_case_node_id')
            ->selectRaw('workflow_case_node_id, max(id) as history_id')
            ->pluck('history_id', 'workflow_case_node_id')
            ->map(fn (mixed $id) => (int) $id)
            ->all();
        $rows = WorkflowCaseAssignment::query()->where('workflow_case_id', $caseId)->whereIn('workflow_case_history_id', array_values($latestApprovals))
            ->orderBy('sequence')->get()->groupBy(fn (WorkflowCaseAssignment $row) => "{$row->workflow_case_history_id}:{$row->workflow_case_node_id}");
        $result = [];

        foreach ($designators as $nodeId => $designatorId) {
            if (isset($latestApprovals[$designatorId])) {
                $result[$nodeId] = array_values(array_map(fn (WorkflowCaseAssignment $row) => (int) $row->user_id, $rows->get("{$latestApprovals[$designatorId]}:{$nodeId}")?->all() ?? []));
            }
        }

        return $result;
    }
}

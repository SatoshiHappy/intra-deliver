<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Builder;

/**
 * 今の指定の 1 か所(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.5 節): 指定されたノード Y の今の指定の履歴の行 h = Y の指定するノード X の
 * 引戻されていない承認の行と、ノードが Y の指定の変更の行のうち id の最大。今の指定 = h が書いた Y の指定の行の集まり(sequence の順)。行が 0 = 0 人を指定した。
 * 計画の中でこの操作の検証済みの指定で上書きするのは呼び手(計画)。承認のダイアログの初期値は引戻された行も含めた X の最新の承認の指定({@see self::latestOf()}。
 * 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md の 5.2 節)。鍵を取らない。
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
        $designators = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)
            ->whereNotNull('designator_workflow_case_node_id')->orderBy('id')
            ->pluck('designator_workflow_case_node_id', 'id')->map(fn (mixed $id) => (int) $id)->all();

        if ($designators === []) {
            return [];
        }

        $current = $this->historyIdsOf($caseId, $designators, $includesPulledBack);
        $rows = WorkflowCaseAssignment::query()->where('workflow_case_id', $caseId)
            ->whereIn('workflow_case_history_id', array_values($current))->orderBy('sequence')->get()
            ->groupBy(fn (WorkflowCaseAssignment $row) => "{$row->workflow_case_history_id}:{$row->workflow_case_node_id}");
        $result = [];

        foreach ($current as $nodeId => $historyId) {
            $assigned = $rows->get("{$historyId}:{$nodeId}")?->all() ?? [];
            $result[$nodeId] = array_values(array_map(fn (WorkflowCaseAssignment $row) => (int) $row->user_id, $assigned));
        }

        return $result;
    }

    /**
     * Y ごとの今の指定の履歴の行 h(X の承認の行と Y の指定の変更の行のうち id の最大。どちらも無い Y は入れない)。
     *
     * @param  array<int, int>  $designators  Y の id → X の id
     * @return array<int, int> Y の id → h の id
     */
    private function historyIdsOf(int $caseId, array $designators, bool $includesPulledBack): array
    {
        $reverted = WorkflowCaseHistory::query()->where('workflow_case_id', $caseId)
            ->whereNotNull('reverted_workflow_case_history_id')->select('reverted_workflow_case_history_id');
        $approvals = $this->latestRowIds($caseId, array_values(array_unique($designators)), WorkflowAction::Approve)
            ->when(! $includesPulledBack, fn (Builder $query) => $query->whereNotIn('id', $reverted));
        // 指定の変更の行は引戻せない種別なので、引戻されたかを見ない(ダイアログの初期値は X の承認だけ)
        $reassigns = $includesPulledBack ? [] : $this->idsOf(
            $this->latestRowIds($caseId, array_keys($designators), WorkflowAction::Reassign),
        );
        $latestApprovals = $this->idsOf($approvals);
        $current = [];

        foreach ($designators as $nodeId => $designatorId) {
            $candidates = array_filter([$latestApprovals[$designatorId] ?? null, $reassigns[$nodeId] ?? null]);

            if ($candidates !== []) {
                $current[$nodeId] = max($candidates);
            }
        }

        return $current;
    }

    /**
     * ノードごとのその種別の行の id の最大の問い合わせ(呼び手が条件を足せる)。
     *
     * @param  list<int>  $nodeIds
     * @return Builder<WorkflowCaseHistory>
     */
    private function latestRowIds(int $caseId, array $nodeIds, WorkflowAction $action): Builder
    {
        return WorkflowCaseHistory::query()->where('workflow_case_id', $caseId)->whereIn('workflow_case_node_id', $nodeIds)
            ->where('action', $action)->groupBy('workflow_case_node_id')->selectRaw('workflow_case_node_id, max(id) as history_id');
    }

    /**
     * @param  Builder<WorkflowCaseHistory>  $query
     * @return array<int, int> ノードの id → 行の id
     */
    private function idsOf(Builder $query): array
    {
        return $query->pluck('history_id', 'workflow_case_node_id')->map(fn (mixed $id) => (int) $id)->all();
    }
}

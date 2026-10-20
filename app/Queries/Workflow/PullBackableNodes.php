<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Support\Collection;

/**
 * 引戻しの 1 規則(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.5)の 1 か所: ノード X の最新の履歴の行 h について、(1) h の処理者が自分で
 * 種別が引戻せる種別({@see WorkflowAction::pullBackable()})、(2) h より後の行 r のどれも、X の子孫(README.md 2 節の冒頭)と h の影響の範囲 T(h)
 * (X、X が展開の行なら親、h の node_changes のノード)を変えていない。r の変えたノード = if(r が引戻し){r のノードと、r が引戻した行の node_changes の
 * ノード}else{r のノードと r の node_changes のノード}。案件の状態(未完了か)は見ない(状態 × 操作の表が見る)。業務処理の判定・画面の can・引戻すノードの
 * 一覧が同じ結果を使う。鍵を取らない。問い合わせは案件の数に依らない(履歴・ノード・辺が各 1 回)。
 */
class PullBackableNodes
{
    /**
     * 案件ごとに、その人が引戻せるノード(写した並び = {@see WorkflowCaseNode::compareInRoute()})。
     *
     * @param  list<int>  $caseIds
     * @param  int  $userId  処理者(3a = 今の利用者 = {@see CaseActor} の処理者)
     * @return array<int, list<WorkflowCaseNode>> 案件の id → 引戻せるノード(展開の行は親を読み込み済み。無ければ空)
     */
    public function of(array $caseIds, int $userId): array
    {
        $result = array_fill_keys($caseIds, []);

        if ($caseIds === []) {
            return $result;
        }

        $histories = WorkflowCaseHistory::query()->whereIn('workflow_case_id', $caseIds)->orderBy('id')->get()->groupBy('workflow_case_id');
        $actedCaseIds = $histories->flatten()->filter(fn (WorkflowCaseHistory $history) => $this->isOwnPullBackable($history, $userId))
            ->map(fn (WorkflowCaseHistory $history) => $history->workflow_case_id)->unique()->values()->all();

        if ($actedCaseIds === []) {
            return $result;
        }

        $nodes = WorkflowCaseNode::query()->with('parent')->whereIn('workflow_case_id', $actedCaseIds)->get()->groupBy('workflow_case_id');
        $edges = WorkflowCaseEdge::query()->whereIn('workflow_case_id', $actedCaseIds)->get()->groupBy('workflow_case_id');

        foreach ($actedCaseIds as $caseId) {
            $result[$caseId] = $this->ofCase($histories->get($caseId) ?? new Collection, $nodes->get($caseId) ?? new Collection, $edges->get($caseId) ?? new Collection, $userId);
        }

        return $result;
    }

    /**
     * 1 つの案件(写した並びで返す)。
     *
     * @param  Collection<int, WorkflowCaseHistory>  $histories  案件の履歴の行(id の昇順)
     * @param  Collection<int, WorkflowCaseNode>  $nodes
     * @param  Collection<int, WorkflowCaseEdge>  $edges
     * @return list<WorkflowCaseNode>
     */
    private function ofCase(Collection $histories, Collection $nodes, Collection $edges, int $userId): array
    {
        $parents = $nodes->mapWithKeys(fn (WorkflowCaseNode $node) => [$node->id => $node->parent_workflow_case_node_id])->all();
        $routeNodeIds = array_keys(array_filter($parents, fn (?int $parentId) => $parentId === null));
        $graph = RouteGraph::of($routeNodeIds, array_values($edges->map(fn (WorkflowCaseEdge $edge) => [$edge->from_workflow_case_node_id, $edge->to_workflow_case_node_id])->all()));
        $byId = $histories->keyBy('id');
        $pullBackable = [];

        foreach ($histories->keyBy('workflow_case_node_id') as $nodeId => $latest) {
            if ($this->isOwnPullBackable($latest, $userId) && ! $this->isTouchedAfter($latest, $histories, $byId, $parents, $graph)) {
                $pullBackable[] = $nodeId;
            }
        }

        return array_values($nodes->filter(fn (WorkflowCaseNode $node) => in_array($node->id, $pullBackable, true))->sort(WorkflowCaseNode::compareInRoute(...))->all());
    }

    /**
     * 10.5 の 1: 行の処理者が自分で、種別が引戻せる種別。
     */
    private function isOwnPullBackable(WorkflowCaseHistory $history, int $userId): bool
    {
        return $history->user_id === $userId && in_array($history->action, WorkflowAction::pullBackable(), true);
    }

    /**
     * 10.5 の 2 の否定: h より後の行のどれかが、X の子孫か T(h) を変えた。子孫は展開の行を親で読む(X が展開の行なら親の子孫。兄弟の展開の行は入らない)。
     *
     * @param  Collection<int, WorkflowCaseHistory>  $histories  案件の履歴の行(id の昇順)
     * @param  Collection<int, WorkflowCaseHistory>  $byId
     * @param  array<int, int|null>  $parents  ノードの id → 親の id
     */
    private function isTouchedAfter(WorkflowCaseHistory $history, Collection $histories, Collection $byId, array $parents, RouteGraph $graph): bool
    {
        $nodeId = $history->workflow_case_node_id;
        $parentId = $parents[$nodeId] ?? null;
        $descendants = array_flip($graph->reachableFrom($parentId ?? $nodeId));
        $scope = array_flip([$nodeId, ...($parentId === null ? [] : [$parentId]), ...self::changedByRow($history)]);
        $changed = $histories->filter(fn (WorkflowCaseHistory $later) => $later->id > $history->id)->flatMap(fn (WorkflowCaseHistory $later) => self::changedBy($later, $byId));

        return $changed->contains(fn (int $changedId) => isset($scope[$changedId]) || isset($descendants[$parents[$changedId] ?? $changedId]));
    }

    /**
     * 行 r の変えたノード(10.5 の 2)。
     *
     * @param  Collection<int, WorkflowCaseHistory>  $byId  履歴の id → 行
     * @return list<int>
     */
    private static function changedBy(WorkflowCaseHistory $row, Collection $byId): array
    {
        $source = $row->action === WorkflowAction::PullBack ? $byId->get($row->reverted_workflow_case_history_id) : $row;

        return [$row->workflow_case_node_id, ...($source === null ? [] : self::changedByRow($source))];
    }

    /**
     * 行の node_changes のノード(引戻しの行は持たない)。
     *
     * @return list<int>
     */
    private static function changedByRow(WorkflowCaseHistory $row): array
    {
        return array_values(array_map(fn (array $change) => (int) $change['node'], $row->node_changes ?? []));
    }
}

<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCaseEdge;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 進める(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.2〜2.4・2.6): ノードが済んだ後、出る辺の先を sort_key の順に見て、合流の判定・分岐開始の
 * 評価・同期開始の通過・到達({@see NodeArrival})を行う。出る辺が無ければ案件を完了(承認)にする。偽の区間の省略は進めない(2.2 を呼ばない)。
 */
class RouteProgress
{
    public function __construct(
        private NodeArrival $arrival,
        private BranchEvaluation $branches,
    ) {}

    /**
     * @throws ValidationException 到達するノードの 0 人など(case)
     */
    public function advance(CaseRouteState $state, int $nodeId): void
    {
        if ($state->isCompleted()) {
            return;
        }

        $edges = $state->edges->from($nodeId);

        if ($edges === []) {
            $state->complete(WorkflowCompletionReason::Approved);

            return;
        }

        foreach ($edges as $edge) {
            $this->visit($state, $edge->to_workflow_case_node_id);
        }
    }

    /**
     * 渡したノードに、その中の横配置・縦配置の展開の行を足す(2 節の冒頭「展開の行は親と一緒に」)。
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function withChildren(CaseRouteState $state, array $ids): array
    {
        $all = $ids;

        foreach ($ids as $id) {
            $all = [...$all, ...array_values($state->childrenOf($id))];
        }

        return $all;
    }

    /**
     * 辺の先のノード T に入る(T が済んでいれば何もしない)。
     */
    private function visit(CaseRouteState $state, int $id): void
    {
        if ($state->isCompleted() || $state->state($id)->isSettled()) {
            return;
        }

        $kind = $state->node($id)->kind;

        if ($kind->isIn(WorkflowNodeKind::closing())) {
            $this->join($state, $id);
        } elseif ($kind === WorkflowNodeKind::BranchStart) {
            $this->branch($state, $id);
        } elseif ($kind === WorkflowNodeKind::SyncStart) {
            $state->pass($id);
            $this->advance($state, $id);
        } elseif (! $this->arrival->arrive($state, $id)) {
            $this->advance($state, $id);
        }
    }

    /**
     * 分岐開始(2.3): (1) 通過させ、評価の結果を出る辺の taken に書く (2) 偽の辺の区間を省く (3) 真の辺の先へ進む。
     *
     * @throws LogicException 真の辺が無いとき(先読みが拒んである)
     */
    private function branch(CaseRouteState $state, int $id): void
    {
        $trueEdges = $this->branches->trueEdges($state, $id);
        $closingId = $state->node($id)->closing_workflow_case_node_id ?? throw new LogicException("分岐開始 {$id} に対の終了が無い");

        if ($trueEdges === []) {
            throw new LogicException("分岐開始 {$id} に真の辺が無い(先読みと食い違った)");
        }

        foreach ($state->edges->from($id) as $edge) {
            $state->edges->setTaken($edge, in_array($edge, $trueEdges, true));
        }

        $state->pass($id);
        $this->skipFalseSections($state, $id, $trueEdges, $closingId);

        foreach ($trueEdges as $edge) {
            $this->visit($state, $edge->to_workflow_case_node_id);
        }
    }

    /**
     * 偽の辺の区間(辺の先から対の終了の手前まで、ほかの真の辺の区間に入らないノード。入れ子の分岐・同期・配置を含む)を省く。
     *
     * @param  list<WorkflowCaseEdge>  $trueEdges
     */
    private function skipFalseSections(CaseRouteState $state, int $branchId, array $trueEdges, int $closingId): void
    {
        $kept = array_merge([], ...array_map(fn (WorkflowCaseEdge $edge) => $this->region($state, $edge, $closingId), $trueEdges));
        $skipped = [];

        foreach ($state->edges->from($branchId) as $edge) {
            $skipped = in_array($edge, $trueEdges, true) ? $skipped : [...$skipped, ...array_diff($this->region($state, $edge, $closingId), $kept)];
        }

        foreach ($this->withChildren($state, array_values(array_unique($skipped))) as $skippedId) {
            $state->skip($skippedId);
        }
    }

    /**
     * 辺の先から対の終了の手前までのノード(辺の先が対の終了なら空)。
     *
     * @return list<int>
     */
    private function region(CaseRouteState $state, WorkflowCaseEdge $edge, int $closingId): array
    {
        $start = $edge->to_workflow_case_node_id;

        return $start === $closingId ? [] : [$start, ...$state->graph->reachableFrom($start, [$closingId])];
    }

    /**
     * 合流(2.4): 区間のノードがすべて済んでいれば終了を通過させて進める。済んでいなければ待つ。
     */
    private function join(CaseRouteState $state, int $closingId): void
    {
        $openingIds = array_filter($state->graph->nodeIds(), fn (int $nodeId) => $state->node($nodeId)->closing_workflow_case_node_id === $closingId);
        $openingId = array_values($openingIds)[0] ?? throw new LogicException("終了 {$closingId} の開始が無い");
        $section = $this->withChildren($state, $state->graph->section($openingId, $closingId));

        foreach ($section as $nodeId) {
            if (! $state->state($nodeId)->isSettled()) {
                return;
            }
        }

        $state->pass($closingId);
        $this->advance($state, $closingId);
    }
}

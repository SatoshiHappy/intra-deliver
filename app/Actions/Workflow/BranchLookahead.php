<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCaseEdge;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 先読み(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.3): 計画の前に、申請のノードから案件の写しを今のレコードの値で辿り(承認のノードで止まらず
 * ルートの最後まで。分岐開始では真の辺へだけ進む)、辿り着く分岐開始のどれかで真の辺が 1 つも無ければ 422(どの操作も。操作の種別で分けない)。
 */
class BranchLookahead
{
    public function __construct(private BranchEvaluation $branches) {}

    /**
     * @throws ValidationException 辿り着く分岐開始で真の辺が無いとき(case)
     * @throws LogicException 申請のノードが無いとき
     */
    public function check(CaseRouteState $state): void
    {
        $applyIds = array_values(array_filter($state->graph->nodeIds(), fn (int $id) => $state->node($id)->kind === WorkflowNodeKind::Apply));
        $visit = [$applyIds[0] ?? throw new LogicException("案件 {$state->case->id} に申請のノードが無い")];
        $seen = [$visit[0] => true];

        for ($index = 0; $index < count($visit); $index++) {
            foreach ($this->nextOf($state, $visit[$index]) as $next) {
                if (! isset($seen[$next])) {
                    $seen[$next] = true;
                    $visit[] = $next;
                }
            }
        }
    }

    /**
     * @return list<int> 先読みで進む先
     *
     * @throws ValidationException 分岐開始で真の辺が無いとき
     */
    private function nextOf(CaseRouteState $state, int $nodeId): array
    {
        $node = $state->node($nodeId);
        $edges = $node->kind === WorkflowNodeKind::BranchStart ? $this->branches->trueEdges($state, $nodeId) : $state->edges->from($nodeId);

        if ($edges === [] && $node->kind === WorkflowNodeKind::BranchStart) {
            throw CaseOperationErrors::noBranchRoute($node->name);
        }

        return array_map(fn (WorkflowCaseEdge $edge) => $edge->to_workflow_case_node_id, $edges);
    }
}

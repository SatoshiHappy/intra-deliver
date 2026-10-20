<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCaseEdge;
use App\Queries\Records\Api\RecordConditions;
use LogicException;

/**
 * 分岐開始から出る辺の条件の評価(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.3): 写した条件を、案件のレコードの今の値で業務データの入れ物の
 * 公開の口 {@see RecordConditions::matches()} に渡す(規則の条件と同じ意味)。1 つの計画で辺ごとに 1 回だけ評価する(先読みと計画が同じ結果を使う)。
 */
class BranchEvaluation
{
    public function __construct(private RecordConditions $conditions) {}

    /**
     * 分岐開始から出る辺のうち、条件が真のもの(sort_key の順)。
     *
     * @return list<WorkflowCaseEdge>
     *
     * @throws LogicException 案件にレコードが無いとき(未完了の案件はレコードを持つ)
     */
    public function trueEdges(CaseRouteState $state, int $branchId): array
    {
        $recordId = $state->case->record_id ?? throw new LogicException("案件 {$state->case->id} にレコードが無い");

        return array_values(array_filter(
            $state->edges->from($branchId),
            fn (WorkflowCaseEdge $edge) => $state->edges->evaluated($edge, fn () => $edge->condition !== null && $this->conditions->matches($recordId, $edge->condition)),
        ));
    }
}

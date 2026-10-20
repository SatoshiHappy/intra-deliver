<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCaseNode;

/**
 * 計画が到達させたノードに到達日時と前処理者を書く(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節)。
 * ノードの状態より先に書くのは、現在のノードは到達日時を持つ検査制約が状態の更新の時に効くため。呼び手のトランザクションの中で書く。
 */
class CaseReachWriter
{
    /**
     * @param  array<int, int>  $ids  仮の id → 書いた行の id({@see CaseRoutePlanWriter::addChildren()})
     */
    public function write(CaseRoutePlan $plan, array $ids): void
    {
        if ($plan->reachedAtNodeIds === []) {
            return;
        }

        WorkflowCaseNode::query()
            ->whereKey(array_map(fn (int $id) => $ids[$id] ?? $id, $plan->reachedAtNodeIds))
            ->update(['reached_at' => now(), ...$plan->previousColumns]);
    }
}

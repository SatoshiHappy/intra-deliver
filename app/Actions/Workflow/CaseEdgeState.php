<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCaseEdge;
use LogicException;

/**
 * 計画の作業の状態のうち案件の辺({@see CaseRouteState} の部品): 出る辺(sort_key・id の順)、分岐開始の評価の結果 taken の写し、条件の評価の結果
 * (先読みと計画で 1 回だけ評価する = docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.3)。
 */
final class CaseEdgeState
{
    /** @var array<int, bool|null> 辺の id → taken */
    private array $taken = [];

    /** @var array<int, bool> 辺の id → 条件の評価の結果 */
    private array $evaluated = [];

    /**
     * @param  array<int, list<WorkflowCaseEdge>>  $edgesFrom  ノードの id → 出る辺(sort_key・id の順)
     */
    public function __construct(private readonly array $edgesFrom)
    {
        foreach ($edgesFrom as $edges) {
            foreach ($edges as $edge) {
                $this->taken[$edge->id] = $edge->taken;
            }
        }
    }

    /**
     * @return list<WorkflowCaseEdge>
     */
    public function from(int $nodeId): array
    {
        return $this->edgesFrom[$nodeId] ?? [];
    }

    /**
     * 辺の条件の評価(1 つの計画で 1 回だけ $evaluate を呼ぶ)。
     *
     * @param  callable(): bool  $evaluate
     */
    public function evaluated(WorkflowCaseEdge $edge, callable $evaluate): bool
    {
        return $this->evaluated[$edge->id] ??= $evaluate();
    }

    /**
     * taken を書く(分岐開始の評価の結果。戻すときは null = README.md 2.1 の「戻す」)。
     */
    public function setTaken(WorkflowCaseEdge $edge, ?bool $taken): void
    {
        $this->taken[$edge->id] = $taken;
    }

    /**
     * taken を写しの値に戻す(引戻しの「写しから戻す」= README.md 2.1)。
     *
     * @param  array<int, bool|null>  $taken  辺の id → taken
     *
     * @throws LogicException 案件の辺でない id のとき
     */
    public function restoreTaken(array $taken): void
    {
        foreach ($taken as $edgeId => $value) {
            $this->taken[$edgeId] = array_key_exists($edgeId, $this->taken) ? $value : throw new LogicException("辺 {$edgeId} は案件の辺でない");
        }
    }

    /**
     * taken を変えた分岐開始の、出る辺の全部の taken(分岐開始の id → 辺の id → [前, 後])。
     *
     * @return array<int, array<int, array{bool|null, bool|null}>>
     */
    public function changedTaken(): array
    {
        $changed = [];

        foreach ($this->edgesFrom as $from => $edges) {
            $pairs = [];

            foreach ($edges as $edge) {
                $pairs[$edge->id] = [$edge->taken, $this->taken[$edge->id]];
            }

            if (array_filter($pairs, fn (array $pair) => $pair[0] !== $pair[1]) !== []) {
                $changed[$from] = $pairs;
            }
        }

        return $changed;
    }
}

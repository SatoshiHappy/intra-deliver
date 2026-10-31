<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowRouteNode;

/**
 * 版のノードの指定するノードと差戻しできる先の式の 1 か所(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節の G-10・G-11)。
 * 定義の検査の判定と、定義の管理の画面の選択の欄の候補が同じ式を使う。DB を読まない。
 */
final readonly class RouteNodeCandidates
{
    /**
     * @param  array<int, WorkflowRouteNode>  $nodes  id → ノード(position の順)
     */
    public function __construct(
        private array $nodes,
        private RouteGraph $graph,
    ) {}

    /**
     * G-10: X は承認のノードで Y と違い、申請のノードから Y へのどの経路も X を通る。X が処理の時に指定なら X の最小は 1 以上。
     */
    public function isDesignatorOf(int $nodeId, int $designatorId): bool
    {
        $designator = $this->nodes[$designatorId];
        $applyId = $this->applyNodeId();

        return $designatorId !== $nodeId
            && $designator->kind->isIn(WorkflowNodeKind::designating())
            && $applyId !== null
            && ! in_array($nodeId, $this->graph->reachableFrom($applyId, [$designatorId]), true)
            && ($designator->target_kind !== WorkflowTargetKind::Designated || $designator->min_assignees >= 1);
    }

    /**
     * ノードの指定するノードになれるノード(position の順)。
     *
     * @return list<int>
     */
    public function designatorsOf(int $nodeId): array
    {
        return array_values(array_filter(
            array_keys($this->nodes),
            fn (int $designatorId) => $this->isDesignatorOf($nodeId, $designatorId),
        ));
    }

    /**
     * G-11: 差戻し元(承認・横配置・縦配置)の差戻しできる先 = 祖先の申請・承認・横配置・縦配置(position の順)。差戻し元になれなければ空。
     *
     * @return list<int>
     */
    public function sendBackTargetsOf(int $sourceId): array
    {
        if (! $this->nodes[$sourceId]->kind->isIn(WorkflowNodeKind::approving())) {
            return [];
        }

        $ancestors = $this->graph->ancestorsOf($sourceId);
        $targeted = fn (int $id) => $this->nodes[$id]->kind->isIn(WorkflowNodeKind::targeted());

        return array_values(array_filter(
            array_keys($this->nodes),
            fn (int $id) => in_array($id, $ancestors, true) && $targeted($id),
        ));
    }

    private function applyNodeId(): ?int
    {
        foreach ($this->nodes as $id => $node) {
            if ($node->kind === WorkflowNodeKind::Apply) {
                return $id;
            }
        }

        return null;
    }
}

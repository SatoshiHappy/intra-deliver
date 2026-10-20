<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowRouteNode;

/**
 * 定義の検査のうち、前処理者の制約と指定するノード(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節の G-9・G-10、処理対象者.md 4.4・5.1)。
 */
class RouteDesignationRules
{
    /**
     * @return list<RouteViolation>
     */
    public function violations(RouteDefinition $route): array
    {
        $violations = [];

        foreach ($route->nodes as $id => $node) {
            if ($node->relative_base === WorkflowRelativeBase::Previous) {
                $violations = [...$violations, ...$this->previousBase($route, $id)];
            }

            if ($node->designator_workflow_route_node_id !== null && ! $this->isValidDesignator($route, $id, $node->designator_workflow_route_node_id)) {
                $violations[] = new RouteViolation('G-10', $route->codes([$id, $node->designator_workflow_route_node_id]));
            }
        }

        return $violations;
    }

    /**
     * G-9: 直前のノード P をたどる(P が分岐開始・同期開始・最小 0 の処理の時に指定のノード(縦配置を除く)なら P の前へ)。たどった先が分岐終了・同期終了・
     * 縦配置なら違反(名乗るのは Y と、途中の最小 0 のノード Z)。
     *
     * @return list<RouteViolation>
     */
    private function previousBase(RouteDefinition $route, int $nodeId): array
    {
        $skippable = [];
        $current = $nodeId;

        while (($previous = $route->graph->incoming($current)[0] ?? null) !== null) {
            $node = $route->node($previous);

            if ($node->kind->isIn(WorkflowNodeKind::previousBlocking())) {
                return [new RouteViolation('G-9', $route->codes([$nodeId, ...$skippable]))];
            }

            if (! $this->isPassedThrough($node)) {
                return [];
            }

            $skippable = $node->kind->isIn(WorkflowNodeKind::opening()) ? $skippable : [...$skippable, $previous];
            $current = $previous;
        }

        return [];
    }

    /**
     * 処理者を持たずに済み得るノード(分岐開始・同期開始・最小 0 の処理の時に指定のノード)。
     */
    private function isPassedThrough(WorkflowRouteNode $node): bool
    {
        return $node->kind->isIn(WorkflowNodeKind::opening())
            || ($node->target_kind === WorkflowTargetKind::Designated && $node->min_assignees === 0);
    }

    /**
     * G-10: X は承認のノードで Y と違い、申請のノードから Y へのどの経路も X を通る。X が処理の時に指定なら X の最小は 1 以上。
     */
    private function isValidDesignator(RouteDefinition $route, int $nodeId, int $designatorId): bool
    {
        $designator = $route->node($designatorId);
        $applyId = $route->applyNodeId();

        return $designatorId !== $nodeId
            && $designator->kind->isIn(WorkflowNodeKind::designating())
            && $applyId !== null
            && ! in_array($nodeId, $route->graph->reachableFrom($applyId, [$designatorId]), true)
            && ($designator->target_kind !== WorkflowTargetKind::Designated || $designator->min_assignees >= 1);
    }
}

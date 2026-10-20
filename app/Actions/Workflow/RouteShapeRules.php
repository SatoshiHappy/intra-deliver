<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowRouteEdge;

/**
 * 定義の検査のうち、ルートの最後・届くこと・辺の向き・辺の数(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節の G-2・G-3・G-4)。
 */
class RouteShapeRules
{
    /**
     * @return list<RouteViolation>
     */
    public function violations(RouteDefinition $route): array
    {
        return [...$this->lastNode($route), ...$this->reachability($route), ...$this->edgeCounts($route)];
    }

    /**
     * G-2: 出る辺の無いノードがちょうど 1 つで、種類はルートの最後になれる種類。
     *
     * @return list<RouteViolation>
     */
    private function lastNode(RouteDefinition $route): array
    {
        $lasts = array_values(array_filter($route->graph->nodeIds(), fn (int $id) => $route->graph->outgoing($id) === []));
        $valid = count($lasts) === 1 && $route->node($lasts[0])->kind->isIn(WorkflowNodeKind::terminal());

        return $valid ? [] : [new RouteViolation('G-2', $route->codes($lasts))];
    }

    /**
     * G-3: 申請のノードがあり、すべてのノードが申請のノードから届き、どの辺も position の小さいノードから大きいノードへ向く。
     *
     * @return list<RouteViolation>
     */
    private function reachability(RouteDefinition $route): array
    {
        $applyId = $route->applyNodeId();

        if ($applyId === null) {
            return [new RouteViolation('G-3', [])];
        }

        $reached = [$applyId, ...$route->graph->reachableFrom($applyId)];
        $unreached = array_values(array_diff($route->graph->nodeIds(), $reached));
        $violations = $unreached === [] ? [] : [new RouteViolation('G-3', $route->codes($unreached))];

        foreach ($route->edges as $edge) {
            if (! $this->isForward($route, $edge)) {
                $violations[] = new RouteViolation('G-3', $route->codes([$edge->from_workflow_route_node_id, $edge->to_workflow_route_node_id]));
            }
        }

        return $violations;
    }

    private function isForward(RouteDefinition $route, WorkflowRouteEdge $edge): bool
    {
        return $route->node($edge->from_workflow_route_node_id)->position < $route->node($edge->to_workflow_route_node_id)->position;
    }

    /**
     * G-4: 開始は出る辺 2 本以上・入る辺 1 本、終了は入る辺 2 本以上・出る辺 1 本まで、ほかは入る辺 1 本まで(申請は 0)・出る辺 1 本まで。
     *
     * @return list<RouteViolation>
     */
    private function edgeCounts(RouteDefinition $route): array
    {
        $violations = [];

        foreach ($route->nodes as $id => $node) {
            $out = count($route->graph->outgoing($id));
            $in = count($route->graph->incoming($id));

            if (! $this->countsFit($node->kind, $in, $out)) {
                $violations[] = new RouteViolation('G-4', [$node->code]);
            }
        }

        return $violations;
    }

    private function countsFit(WorkflowNodeKind $kind, int $in, int $out): bool
    {
        return match (true) {
            $kind->isIn(WorkflowNodeKind::opening()) => $out >= 2 && $in === 1,
            $kind->isIn(WorkflowNodeKind::closing()) => $in >= 2 && $out <= 1,
            $kind === WorkflowNodeKind::Apply => $in === 0 && $out <= 1,
            default => $in <= 1 && $out <= 1,
        };
    }
}

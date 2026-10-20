<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;

/**
 * 定義の検査のうち、区間の形(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節の G-5。合流の判定 = 案件の操作/README.md 2.4 の前提):
 * 開始と対の終了が同じ系、開始から出るどの経路も対の終了を通り、区間の辺は区間の中か対の終了へ向き、区間と対の終了へは区間か開始からだけ入る、
 * 区間どうしは入れ子か交わらない、どの終了も 1 つの開始の対。
 */
class RouteSectionRules
{
    /**
     * @return list<RouteViolation>
     */
    public function violations(RouteDefinition $route): array
    {
        $blocks = [];
        $violations = [];

        foreach ($this->openings($route) as $openingId => $closingId) {
            $section = $route->graph->section($openingId, $closingId);
            $blocks[$openingId] = [$openingId, ...$section, $closingId];

            if (! $this->isClosedSection($route, $openingId, $closingId, $section)) {
                $violations[] = new RouteViolation('G-5', $route->codes([$openingId, $closingId]));
            }
        }

        return [...$violations, ...$this->unpairedClosings($route), ...$this->crossingSections($route, $blocks)];
    }

    /**
     * @return array<int, int> 開始の id → 対の終了の id
     */
    private function openings(RouteDefinition $route): array
    {
        $openings = [];

        foreach ($route->nodes as $id => $node) {
            if ($node->kind->isIn(WorkflowNodeKind::opening()) && $node->closing_workflow_route_node_id !== null) {
                $openings[$id] = $node->closing_workflow_route_node_id;
            }
        }

        return $openings;
    }

    /**
     * @param  list<int>  $section
     */
    private function isClosedSection(RouteDefinition $route, int $openingId, int $closingId, array $section): bool
    {
        $inside = [...$section, $closingId];
        $entries = [$openingId, ...$section];

        return $route->node($closingId)->kind === $route->node($openingId)->kind->closingKind()
            && in_array($closingId, $route->graph->reachableFrom($openingId), true)
            && $this->allLeadInto($route, $entries, $inside)
            && $this->allComeFrom($route, $inside, $entries);
    }

    /**
     * どのノードも出る辺を持ち、その先が $targets の中。
     *
     * @param  list<int>  $nodeIds
     * @param  list<int>  $targets
     */
    private function allLeadInto(RouteDefinition $route, array $nodeIds, array $targets): bool
    {
        foreach ($nodeIds as $id) {
            $outgoing = $route->graph->outgoing($id);

            if ($outgoing === [] || array_diff($outgoing, $targets) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * どのノードへも $sources の中からだけ入る。
     *
     * @param  list<int>  $nodeIds
     * @param  list<int>  $sources
     */
    private function allComeFrom(RouteDefinition $route, array $nodeIds, array $sources): bool
    {
        foreach ($nodeIds as $id) {
            if (array_diff($route->graph->incoming($id), $sources) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * 終了の種類のノードで、開始の対になっていない・2 つ以上の開始の対になっているもの。
     *
     * @return list<RouteViolation>
     */
    private function unpairedClosings(RouteDefinition $route): array
    {
        $pairs = array_count_values(array_values($this->openings($route)));
        $violations = [];

        foreach ($route->nodes as $id => $node) {
            if ($node->kind->isIn(WorkflowNodeKind::closing()) && ($pairs[$id] ?? 0) !== 1) {
                $violations[] = new RouteViolation('G-5', [$node->code]);
            }
        }

        return $violations;
    }

    /**
     * 入れ子でも交わらないでもない 2 つの区間(開始・区間・対の終了の集まりで比べる)。
     *
     * @param  array<int, list<int>>  $blocks  開始の id → 開始・区間・対の終了
     * @return list<RouteViolation>
     */
    private function crossingSections(RouteDefinition $route, array $blocks): array
    {
        $violations = [];
        $openingIds = array_keys($blocks);

        foreach ($openingIds as $index => $first) {
            foreach (array_slice($openingIds, $index + 1) as $second) {
                if ($this->crosses($blocks[$first], $blocks[$second])) {
                    $violations[] = new RouteViolation('G-5', $route->codes([$first, $second]));
                }
            }
        }

        return $violations;
    }

    /**
     * @param  list<int>  $first
     * @param  list<int>  $second
     */
    private function crosses(array $first, array $second): bool
    {
        $shared = array_intersect($first, $second);

        return $shared !== [] && array_diff($first, $second) !== [] && array_diff($second, $first) !== [];
    }
}

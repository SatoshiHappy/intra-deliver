<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use App\Queries\Workflow\RouteGraph;

/**
 * 定義の検査(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節): 1 つの版のノード・辺・差戻しできる先を読み、ルートの形と設定の規則
 * (G-2〜G-7・G-9〜G-11)の違反の一覧を返す。見るのは版の行と、版の行が指す対象者の形だけ(ほかの版・案件・業務モジュールの今の定義を見ない)。
 * 違反をどう失敗にするか(422 か LogicException か)は呼び手の業務処理が決める。呼び手のトランザクションの中で、書いた後に呼ぶ。
 */
class RouteDefinitionValidator
{
    public function __construct(
        private RouteShapeRules $shape,
        private RouteSectionRules $sections,
        private RouteSettingRules $settings,
        private RouteDesignationRules $designations,
    ) {}

    /**
     * @return list<RouteViolation> 違反(無ければ空。規則の番号の順)
     */
    public function violations(int $versionId): array
    {
        $route = $this->load($versionId);
        $violations = [
            ...$this->shape->violations($route),
            ...$this->sections->violations($route),
            ...$this->settings->violations($route),
            ...$this->designations->violations($route),
        ];
        usort($violations, fn (RouteViolation $a, RouteViolation $b) => (int) substr($a->rule, 2) <=> (int) substr($b->rule, 2));

        return $violations;
    }

    private function load(int $versionId): RouteDefinition
    {
        $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get()->keyBy('id')->all();
        $positions = array_map(fn (WorkflowRouteNode $node) => $node->position, $nodes);
        $edges = WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->orderBy('sort_key')->orderBy('id')->get()->all();
        usort($edges, fn (WorkflowRouteEdge $a, WorkflowRouteEdge $b) => $positions[$a->from_workflow_route_node_id] <=> $positions[$b->from_workflow_route_node_id]);
        $sendBacks = WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get()->all();
        $graph = RouteGraph::of(array_keys($nodes), array_map(fn (WorkflowRouteEdge $edge) => [$edge->from_workflow_route_node_id, $edge->to_workflow_route_node_id], $edges));

        return new RouteDefinition($nodes, $edges, array_values($sendBacks), $graph);
    }
}

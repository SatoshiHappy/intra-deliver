<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;

/**
 * 1 つの版のルート(ノード・辺とグラフ)を読む(定義の管理の画面の図と選択の欄 = docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 3.3 節)。
 * 辺の並び = 出るノードの position・sort_key・id(定義の検査と同じ)。鍵を取らない。
 */
final readonly class FlowVersionRoute
{
    /**
     * @param  array<int, WorkflowRouteNode>  $nodes  id → ノード(position の順)
     * @param  list<WorkflowRouteEdge>  $edges
     */
    private function __construct(
        public array $nodes,
        public array $edges,
        public RouteGraph $graph,
    ) {}

    public static function load(int $versionId): self
    {
        $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get()
            ->keyBy('id')->all();
        $positions = array_map(fn (WorkflowRouteNode $node) => $node->position, $nodes);
        $edges = WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)
            ->orderBy('sort_key')->orderBy('id')->get()->all();
        usort($edges, fn (WorkflowRouteEdge $a, WorkflowRouteEdge $b) => $positions[$a->from_workflow_route_node_id]
            <=> $positions[$b->from_workflow_route_node_id]);
        $pairs = array_map(
            fn (WorkflowRouteEdge $edge) => [$edge->from_workflow_route_node_id, $edge->to_workflow_route_node_id],
            $edges,
        );

        return new self($nodes, $edges, RouteGraph::of(array_keys($nodes), $pairs));
    }

    public function candidates(): RouteNodeCandidates
    {
        return new RouteNodeCandidates($this->nodes, $this->graph);
    }

    /**
     * 処理の時に指定するノードの id → 指定するノードの名前(設定から作る文の入力)。
     *
     * @return array<int, string>
     */
    public function designatorNames(): array
    {
        $names = [];

        foreach ($this->nodes as $id => $node) {
            $designatorId = $node->designator_workflow_route_node_id;

            if ($designatorId !== null) {
                $names[$id] = $this->nodes[(int) $designatorId]->name;
            }
        }

        return $names;
    }

    /**
     * コードのノード(無ければ null)。
     */
    public function nodeByCode(string $code): ?WorkflowRouteNode
    {
        foreach ($this->nodes as $node) {
            if ($node->code === $code) {
                return $node;
            }
        }

        return null;
    }
}

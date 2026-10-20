<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use App\Queries\Workflow\RouteGraph;

/**
 * 定義の検査が見る 1 つの版の行(ノード・辺・差戻しできる先とグラフ。{@see RouteDefinitionValidator} が読み、規則のクラスに渡す)。
 */
final readonly class RouteDefinition
{
    /**
     * @param  array<int, WorkflowRouteNode>  $nodes  id → ノード(position の順)
     * @param  list<WorkflowRouteEdge>  $edges  出るノードの position・sort_key・id の順
     * @param  list<WorkflowRouteSendBack>  $sendBacks
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $sendBacks,
        public RouteGraph $graph,
    ) {}

    public function node(int $id): WorkflowRouteNode
    {
        return $this->nodes[$id];
    }

    public function code(int $id): string
    {
        return $this->nodes[$id]->code;
    }

    /**
     * 申請のノード(無ければ null)。
     */
    public function applyNodeId(): ?int
    {
        foreach ($this->nodes as $id => $node) {
            if ($node->kind === WorkflowNodeKind::Apply) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    public function codes(array $ids): array
    {
        return array_map(fn (int $id) => $this->code($id), $ids);
    }
}

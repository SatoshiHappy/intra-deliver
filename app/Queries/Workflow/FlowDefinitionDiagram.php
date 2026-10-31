<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Records\Api\RecordConditions;
use DateTimeInterface;

/**
 * 定義の図(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.2 節の図の形、図と意匠とE2E.md 8 節・9.3 節)。
 * 案件の図と同じ形で、状態・人は持たず、短い文は設定から作る。配置のノードは仮の札、縦配置は完了の条件と人数の範囲を持つ。
 * 分岐の辺の条件の文は、コンテンツの業務モジュールの今の定義で作る({@see RecordConditions::describe()})。鍵を取らない。
 *
 * @phpstan-type DefinitionNode array{id: int, code: string, name: string, kind: string, parentId: null, sequence: null,
 *     subtitle: string|null, state: null, people: list<never>,
 *     placeholders: list<array{label: string, optional: bool}>, consensus: array{text: string}|null}
 * @phpstan-type DefinitionEdge array{from: int, to: int, label: string|null, conditionText: string|null, taken: null}
 */
class FlowDefinitionDiagram
{
    public function __construct(private StepTargets $targets, private RecordConditions $conditions) {}

    /**
     * @param  int  $moduleId  フローのコンテンツの業務モジュール(条件の文の項目の名前を引く)
     * @return array{nodes: list<DefinitionNode>, edges: list<DefinitionEdge>}
     */
    public function of(FlowVersionRoute $route, int $moduleId, DateTimeInterface $date, string $localeCode): array
    {
        $targets = $this->targets->of(array_values($route->nodes), $route->designatorNames(), $date, $localeCode);

        return [
            'nodes' => array_values(array_map(
                fn (WorkflowRouteNode $node) => $this->nodeOf($node, $targets[$node->id]),
                $route->nodes,
            )),
            'edges' => array_map(fn (WorkflowRouteEdge $edge) => [
                'from' => (int) $edge->from_workflow_route_node_id,
                'to' => (int) $edge->to_workflow_route_node_id,
                'label' => $edge->label,
                'conditionText' => $edge->condition === null
                    ? null : $this->conditions->describe($moduleId, $edge->condition),
                'taken' => null,
            ], $route->edges),
        ];
    }

    /**
     * @return DefinitionNode
     */
    private function nodeOf(WorkflowRouteNode $node, ?StepTarget $target): array
    {
        $isExpanding = $node->kind->isIn(WorkflowNodeKind::expanding()) && $target !== null;
        $min = (int) $target?->min;
        $max = (int) $target?->max;
        $rule = $node->decision_rule;
        $consensus = fn () => StepDescriptions::definitionConsensus($rule, $node->decision_value, $min, $max);

        return [
            'id' => $node->id,
            'code' => $node->code,
            'name' => $node->name,
            'kind' => $node->kind->value,
            'parentId' => null,
            'sequence' => null,
            'subtitle' => StepDescriptions::subtitle($node->kind, $target),
            'state' => null,
            'people' => [],
            'placeholders' => $isExpanding ? StepDescriptions::placeholders($min, $max) : [],
            'consensus' => $node->kind === WorkflowNodeKind::Parallel
                ? ['text' => $consensus()] : null,
        ];
    }
}

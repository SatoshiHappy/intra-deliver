<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Records\Api\RecordConditions;
use Closure;
use DateTimeInterface;

/**
 * 定義の図(docs の 設計/ワークフロー/画面/フロー図.md 10 節・11 節)。
 * 案件の図と同じ形で、状態・人は持たず、短い文(期限の日数を含む)と確認の札は設定から作る(設計/ワークフロー/案件の運用/画面/管理と通知の画面.md
 * 7.6 節)。配置のノードは仮の札、縦配置は完了の条件と人数の範囲を持つ。
 * 分岐の辺の条件の文は、コンテンツの業務モジュールの今の定義で作る({@see RecordConditions::describe()})。鍵を取らない。
 *
 * @phpstan-import-type SubtitlePart from StepDescriptions
 * @phpstan-import-type SubjectRow from StepTarget
 *
 * @phpstan-type DefinitionNode array{id: int, code: string, name: string, kind: string, parentId: null, sequence: null,
 *     subtitle: list<SubtitlePart>|null, state: null, people: list<never>,
 *     placeholders: list<array{label: string, optional: bool}>, consensus: array{text: string}|null,
 *     confirmBadge: string|null}
 * @phpstan-type DefinitionEdge array{from: int, to: int, label: string|null, conditionText: string|null, taken: null}
 */
class FlowDefinitionDiagram
{
    public function __construct(
        private StepTargets $targets,
        private RecordConditions $conditions,
        private SubjectRows $subjectRows,
    ) {}

    /**
     * 処理対象者の行は呼び手が渡す行を引く関数で引き、確認の札の名前は所属を引かない {@see SubjectRows::namesOf()} で引く。
     *
     * @param  int  $moduleId  フローのコンテンツの業務モジュール(条件の文の項目の名前を引く)
     * @param  Closure(list<int>): array<int, list<SubjectRow>>  $rowsOf  対象者の行を引く関数({@see StepTargets::withRowsOf()})
     * @return array{nodes: list<DefinitionNode>, edges: list<DefinitionEdge>}
     */
    public function of(
        FlowVersionRoute $route,
        int $moduleId,
        Closure $rowsOf,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $nodes = array_values($route->nodes);
        $targets = $this->targets->withRowsOf($nodes, $route->designatorNames(), $rowsOf, $date, $localeCode);
        $confirmGroupIds = StepTargets::idsOf(array_map(
            fn (WorkflowRouteNode $node) => $node->confirm_authz_subject_group_id,
            $nodes,
        ));
        $confirmers = $this->subjectRows->namesOf($confirmGroupIds, $date, $localeCode);

        return [
            'nodes' => array_values(array_map(
                fn (WorkflowRouteNode $node) => $this->nodeOf(
                    $node, $targets[$node->id], $confirmers[(int) $node->confirm_authz_subject_group_id] ?? [],
                ),
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
     * @param  list<string>  $confirmers  確認する人の名前(条件の作った順)
     * @return DefinitionNode
     */
    private function nodeOf(WorkflowRouteNode $node, ?StepTarget $target, array $confirmers): array
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
            'subtitle' => StepDescriptions::subtitle($target, $node->deadline_days),
            'state' => null,
            'people' => [],
            'placeholders' => $isExpanding ? StepDescriptions::placeholders($min, $max) : [],
            'consensus' => $node->kind === WorkflowNodeKind::Parallel
                ? ['text' => $consensus()] : null,
            'confirmBadge' => StepDescriptions::confirmBadge($confirmers),
        ];
    }
}

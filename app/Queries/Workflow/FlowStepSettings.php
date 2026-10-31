<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use DateTimeInterface;
use LogicException;

/**
 * 定義の管理の画面の「図で選んだステップ」の欄(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 3 節の StepSettings、3.3 節)と、
 * 選択の欄の指定するステップの候補。候補は定義の検査と同じ式({@see RouteNodeCandidates})。持たない設定は null。鍵を取らない。
 *
 * @phpstan-import-type SubjectRow from StepTarget
 *
 * @phpstan-type StepTargetSettings array{kind: 'fixed', rows: list<SubjectRow>}
 *     |array{kind: 'relative', base: string, scope: string, companyPostId: int|null}
 *     |array{kind: 'designated', designatorCode: string, min: int, max: int}
 * @phpstan-type NodeChoice array{code: string, name: string}
 * @phpstan-type StepSettings array{code: string, name: string, kind: string, summary: string,
 *     target: StepTargetSettings|null, preventsApplicantApproval: bool|null,
 *     sendBack: array{candidates: list<NodeChoice>, selected: list<string>}|null,
 *     decision: array{rule: string, value: int|null}|null}
 */
class FlowStepSettings
{
    public function __construct(private StepTargets $targets) {}

    /**
     * @return StepSettings
     */
    public function of(
        FlowVersionRoute $route,
        WorkflowRouteNode $node,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $designatorNames = array_intersect_key($route->designatorNames(), [$node->id => true]);
        $target = $this->targets->of([$node], $designatorNames, $date, $localeCode)[$node->id];
        $isApproving = $node->kind->isIn(WorkflowNodeKind::approving());

        return [
            'code' => $node->code,
            'name' => $node->name,
            'kind' => $node->kind->value,
            'summary' => StepDescriptions::summary(
                $node->kind, $node->name, $target, $node->decision_rule, $node->decision_value,
            ),
            'target' => $target === null ? null : $this->targetOf($route, $node, $target),
            'preventsApplicantApproval' => $isApproving ? $node->prevents_applicant_approval : null,
            'sendBack' => $isApproving ? $this->sendBackOf($route, $node) : null,
            'decision' => $node->kind === WorkflowNodeKind::Parallel ? [
                'rule' => $node->decision_rule->value ?? throw new LogicException("縦配置 {$node->id} に可決の条件が無い"),
                'value' => $node->decision_value,
            ] : null,
        ];
    }

    /**
     * 指定するステップの候補(承認・横配置・縦配置だけ。ほかは空。並び = 写した並び)。
     *
     * @return list<NodeChoice>
     */
    public function designatorsOf(FlowVersionRoute $route, WorkflowRouteNode $node): array
    {
        if (! $node->kind->isIn(WorkflowNodeKind::approving())) {
            return [];
        }

        return self::choices($route, $route->candidates()->designatorsOf($node->id));
    }

    /**
     * @return StepTargetSettings
     */
    private function targetOf(FlowVersionRoute $route, WorkflowRouteNode $node, StepTarget $target): array
    {
        return match ($target->kind) {
            WorkflowTargetKind::Fixed => ['kind' => 'fixed', 'rows' => $target->rows],
            WorkflowTargetKind::Relative => [
                'kind' => 'relative',
                'base' => $target->base->value ?? throw new LogicException("ノード {$node->id} に起点が無い"),
                'scope' => $target->scope->value ?? throw new LogicException("ノード {$node->id} に組織の範囲が無い"),
                'companyPostId' => $target->companyPostId,
            ],
            WorkflowTargetKind::Designated => [
                'kind' => 'designated',
                'designatorCode' => $route->nodes[(int) $node->designator_workflow_route_node_id]->code,
                'min' => (int) $target->min,
                'max' => (int) $target->max,
            ],
        };
    }

    /**
     * 差戻しできる先の候補(G-11)と今の選択(どちらも写した並び)。
     *
     * @return array{candidates: list<NodeChoice>, selected: list<string>}
     */
    private function sendBackOf(FlowVersionRoute $route, WorkflowRouteNode $node): array
    {
        $selected = WorkflowRouteSendBack::query()->where('workflow_route_node_id', $node->id)
            ->pluck('target_workflow_route_node_id')->map(fn (mixed $id) => (int) $id)->all();
        $selectedIds = array_values(array_filter(
            array_keys($route->nodes),
            fn (int $id) => in_array($id, $selected, true),
        ));

        return [
            'candidates' => self::choices($route, $route->candidates()->sendBackTargetsOf($node->id)),
            'selected' => array_map(fn (int $id) => $route->nodes[$id]->code, $selectedIds),
        ];
    }

    /**
     * @param  list<int>  $nodeIds
     * @return list<NodeChoice>
     */
    private static function choices(FlowVersionRoute $route, array $nodeIds): array
    {
        return array_map(
            fn (int $id) => ['code' => $route->nodes[$id]->code, 'name' => $route->nodes[$id]->name],
            $nodeIds,
        );
    }
}

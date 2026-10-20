<?php

namespace App\Actions\Workflow;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Queries\Authz\Api\SelectionSubjects;
use App\Queries\Records\Api\RecordConditions;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 定義の検査のうち、分岐の辺・処理対象者の対象者の形・差戻しできる先(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節の G-6・G-7・G-11)。
 * 対象者の形は認可の公開の口 {@see SelectionSubjects} で読む(認可の表を直接読まない。名前は使わないので日付とロケールは結果に効かない)。
 */
class RouteSettingRules
{
    public function __construct(private SelectionSubjects $subjects, private RecordConditions $conditions) {}

    /**
     * @return list<RouteViolation>
     */
    public function violations(RouteDefinition $route): array
    {
        return [...$this->branchEdges($route), ...$this->fixedTargets($route), ...$this->sendBacks($route)];
    }

    /**
     * G-6: 分岐開始から出る辺はどれも名前と条件(規則の条件の JSON の形)を持ち、ほかの辺は持たない。
     *
     * @return list<RouteViolation>
     */
    private function branchEdges(RouteDefinition $route): array
    {
        $violations = [];

        foreach ($route->edges as $edge) {
            $fromBranch = $route->node($edge->from_workflow_route_node_id)->kind === WorkflowNodeKind::BranchStart;
            $valid = $fromBranch ? $this->hasBranchCondition($edge) : $edge->label === null && $edge->condition === null;

            if (! $valid) {
                $violations[] = new RouteViolation('G-6', $route->codes([$edge->from_workflow_route_node_id, $edge->to_workflow_route_node_id]));
            }
        }

        return $violations;
    }

    private function hasBranchCondition(WorkflowRouteEdge $edge): bool
    {
        return $edge->label !== null && $this->conditions->isWellFormed($edge->condition);
    }

    /**
     * G-7: 対象者で指定の対象者は用途 selection で、組み方 any・比較 eq だけ(条件の相手の種類は口が 4 種類に限る)。
     *
     * @return list<RouteViolation>
     */
    private function fixedTargets(RouteDefinition $route): array
    {
        $fixed = array_filter($route->nodes, fn ($node) => $node->target_kind === WorkflowTargetKind::Fixed);
        $groupIds = array_values(array_unique(array_map(fn ($node) => (int) $node->authz_subject_group_id, $fixed)));
        $shapes = $this->subjects->conditionsOf($groupIds, CarbonImmutable::today(), AccessContext::LOCALE_CODE);
        $violations = [];

        foreach ($fixed as $node) {
            $shape = $shapes[(int) $node->authz_subject_group_id] ?? null;
            $valid = $shape !== null && $shape['combination'] === SubjectCombination::Any
                && array_filter($shape['conditions'], fn (array $condition) => $condition['comparison'] !== ConditionOperator::Equal) === [];

            if (! $valid) {
                $violations[] = new RouteViolation('G-7', [$node->code]);
            }
        }

        return $violations;
    }

    /**
     * G-11: 差戻し元は承認・横配置・縦配置、先は差戻し元の祖先の申請・承認・横配置・縦配置。
     *
     * @return list<RouteViolation>
     */
    private function sendBacks(RouteDefinition $route): array
    {
        $violations = [];

        foreach ($route->sendBacks as $sendBack) {
            $source = $sendBack->workflow_route_node_id;
            $target = $sendBack->target_workflow_route_node_id;
            $valid = $route->node($source)->kind->isIn(WorkflowNodeKind::approving())
                && $route->node($target)->kind->isIn(WorkflowNodeKind::targeted())
                && in_array($target, $route->graph->ancestorsOf($source), true);

            if (! $valid) {
                $violations[] = new RouteViolation('G-11', $route->codes([$source, $target]));
            }
        }

        return $violations;
    }
}

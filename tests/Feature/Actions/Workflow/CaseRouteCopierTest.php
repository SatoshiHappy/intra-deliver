<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseRouteCopier;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseSendBack;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の写し(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.2〜3.4、区切り 2 の残課題 #1): 指定するノード・対の終了を持つ版のノード・辺・
 * 差戻しできる先を全部写せる(参照される側を先に書く)。写したノードはすべて到達していない、辺の taken は NULL、指す列は同じ案件のノード。
 */
final class CaseRouteCopierTest extends TransactionalTestCase
{
    use BuildsRouteVersions;

    public function test_copies_a_version_with_designators_and_closings(): void
    {
        $versionId = $this->addVersion($this->r1());
        $case = $this->caseOf($versionId);

        $this->assertSame($this->routeShape($versionId), $this->caseShape($case));
        $this->assertSame([WorkflowNodeState::Unreached], WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->pluck('state')->unique()->values()->all());
        $this->assertSame([null], WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->pluck('taken')->unique()->values()->all());
    }

    public function test_the_copy_returns_the_nodes_in_the_position_order_of_the_version(): void
    {
        $versionId = $this->addVersion($this->r1());
        $case = WorkflowCase::factory()->active()->createOne(['workflow_flow_id' => WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id, 'workflow_flow_version_id' => $versionId, 'base_date' => '2026-10-13']);

        $copied = array_values(app(CaseRouteCopier::class)->copy($case, $versionId));

        $this->assertSame(['apply', 'section_chief', 'budget', 'manager', 'budget_end', 'council', 'owner'], array_map(fn (WorkflowCaseNode $node) => $node->code, $copied));
        $this->assertSame($copied[1]->id, $copied[5]->designator_workflow_case_node_id);
        $this->assertSame($copied[4]->id, $copied[2]->closing_workflow_case_node_id);
    }

    public function test_the_apply_operation_copies_the_edges_and_send_backs_of_the_version(): void
    {
        $this->createFlow();
        $case = $this->applyAs($this->seededUser('u0001'));

        $this->assertSame($this->routeShape((int) $case->workflow_flow_version_id), $this->caseShape($case));
        $this->assertSame([2, 3], [WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->count(), WorkflowCaseSendBack::query()->where('workflow_case_id', $case->id)->count()]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function routeShape(int $versionId): array
    {
        $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get();
        $codes = $nodes->pluck('code', 'id');

        return [
            'nodes' => $nodes->map(fn (WorkflowRouteNode $node) => [$node->position, $node->code, $node->name, $node->kind->value, $node->target_kind?->value, $node->authz_subject_group_id, $node->company_post_id,
                $node->min_assignees, $node->max_assignees, $node->decision_rule?->value, $node->decision_value, $node->prevents_applicant_approval,
                $codes[$node->designator_workflow_route_node_id] ?? null, $codes[$node->closing_workflow_route_node_id] ?? null])->all(),
            'edges' => WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get()
                ->map(fn (WorkflowRouteEdge $edge) => [$codes[$edge->from_workflow_route_node_id], $codes[$edge->to_workflow_route_node_id], $edge->sort_key, $edge->label, $edge->condition])->all(),
            'sendBacks' => WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get()
                ->map(fn (WorkflowRouteSendBack $row) => $codes[$row->workflow_route_node_id].'>'.$codes[$row->target_workflow_route_node_id])->all(),
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function caseShape(WorkflowCase $case): array
    {
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->orderBy('position')->get();
        $codes = $nodes->pluck('code', 'id');

        return [
            'nodes' => $nodes->map(fn (WorkflowCaseNode $node) => [$node->position, $node->code, $node->name, $node->kind->value, $node->target_kind?->value, $node->authz_subject_group_id, $node->company_post_id,
                $node->min_assignees, $node->max_assignees, $node->decision_rule?->value, $node->decision_value, $node->prevents_applicant_approval,
                $codes[$node->designator_workflow_case_node_id] ?? null, $codes[$node->closing_workflow_case_node_id] ?? null])->all(),
            'edges' => WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->orderBy('id')->get()
                ->map(fn (WorkflowCaseEdge $edge) => [$codes[$edge->from_workflow_case_node_id], $codes[$edge->to_workflow_case_node_id], $edge->sort_key, $edge->label, $edge->condition])->all(),
            'sendBacks' => WorkflowCaseSendBack::query()->where('workflow_case_id', $case->id)->orderBy('id')->get()
                ->map(fn (WorkflowCaseSendBack $row) => $codes[$row->workflow_case_node_id].'>'.$codes[$row->target_workflow_case_node_id])->all(),
        ];
    }
}

<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\AddRouteVersion;
use App\Actions\Workflow\RouteDefinitionViolated;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 形つきの版を作る業務処理(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 1 節): 最後の版の後ろにだけ足し、開始日は最後の版の開始日より後だけを確かめる。
 * 同じトランザクションで最後の版の終了日を新しい開始日にする。ノードは参照される側から書き(指定するノード・対の終了つきの版が書ける)、定義の検査を通す。
 * 失敗はどれも何も残さない。
 */
final class AddRouteVersionTest extends TransactionalTestCase
{
    use BuildsRouteVersions;
    use CatchesValidationErrors;

    public function test_adds_the_version_after_the_last_one_and_closes_the_last_period(): void
    {
        $this->createFlow();
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;

        $second = $this->addVersion($this->r1(), '2025-05-01');
        $third = app(AddRouteVersion::class)->execute(self::FLOW_CODE, CarbonImmutable::parse('2026-10-01'), ...[...$this->r3(), true]);

        $this->assertSame([
            [1, '2025-04-01', '2025-05-01', false],
            [2, '2025-05-01', '2026-10-01', false],
            [3, '2026-10-01', null, true],
        ], WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->orderBy('number')->get()
            ->map(fn (WorkflowFlowVersion $version) => [$version->number, $version->start_date->toDateString(), $version->end_date?->toDateString(), $version->is_deleted])->all());
        $this->assertSame([$second, $third], WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->where('number', '>', 1)->orderBy('number')->pluck('id')->all());
    }

    public function test_writes_the_nodes_edges_and_send_backs_of_the_shape(): void
    {
        $versionId = $this->addVersion($this->r1());
        $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->orderBy('position')->get();
        $codes = $nodes->pluck('code', 'id');

        $this->assertSame(
            [[1, 'apply', 'apply', null, null], [2, 'section_chief', 'approve', null, null], [3, 'budget', 'branch_start', null, 'budget_end'], [4, 'manager', 'approve', null, null],
                [5, 'budget_end', 'branch_end', null, null], [6, 'council', 'parallel', 'section_chief', null], [7, 'owner', 'approve', 'section_chief', null]],
            $nodes->map(fn (WorkflowRouteNode $node) => [$node->position, $node->code, $node->kind->value, $codes[$node->designator_workflow_route_node_id] ?? null, $codes[$node->closing_workflow_route_node_id] ?? null])->all(),
        );
        $this->assertSame(
            [['apply', 'section_chief', 0, null], ['section_chief', 'budget', 0, null], ['budget', 'manager', 1, '予算 300 万円以上'], ['budget', 'budget_end', 2, '何もしない'], ['manager', 'budget_end', 0, null], ['budget_end', 'council', 0, null], ['council', 'owner', 0, null]],
            WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get()->map(fn (WorkflowRouteEdge $edge) => [$codes[$edge->from_workflow_route_node_id], $codes[$edge->to_workflow_route_node_id], $edge->sort_key, $edge->label])->all(),
        );
        $this->assertEquals(['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]], WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->where('sort_key', 1)->sole()->condition);
        $this->assertSame(
            ['section_chief>apply', 'manager>apply', 'manager>section_chief', 'council>apply', 'council>section_chief', 'owner>apply', 'owner>section_chief'],
            WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $versionId)->orderBy('id')->get()->map(fn (WorkflowRouteSendBack $row) => $codes[$row->workflow_route_node_id].'>'.$codes[$row->target_workflow_route_node_id])->all(),
        );
    }

    public function test_a_start_date_on_or_before_the_start_of_the_last_version_is_rejected_and_nothing_changes(): void
    {
        $this->createFlow();
        $before = $this->rows();

        foreach (['2025-04-01', '2025-03-31'] as $date) {
            $errors = $this->validationErrors(fn () => $this->addVersion($this->r3(), $date));
            $this->assertSame(['start_date' => '開始日は版 1 の開始日 2025-04-01 より後の日付を入力してください'], $errors);
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_input_errors_are_rejected_with_their_keys_and_nothing_changes(): void
    {
        $this->createFlow();
        $before = $this->rows();
        [$nodes, $edges] = $this->r1();

        $cases = [
            'nodes.1.code' => [[$nodes[0], new RouteNodeInput('apply', '課長', WorkflowNodeKind::Approve), ...array_slice($nodes, 2)], $edges],
            'nodes.6.designator_code' => [[...array_slice($nodes, 0, 6), new RouteNodeInput('owner', '責任者', WorkflowNodeKind::Approve, $nodes[6]->targetKind, designatorCode: 'nobody', minAssignees: 1, maxAssignees: 1)], $edges],
            'edges.6.to_code' => [$nodes, [...array_slice($edges, 0, 6), new RouteEdgeInput('council', 'nowhere')]],
            'edges.2.condition.all.0.field' => [$nodes, [$edges[0], $edges[1], new RouteEdgeInput('budget', 'manager', 1, '予算', ['all' => [['field' => 'no_such_field', 'op' => 'ge', 'value' => 1]]]), ...array_slice($edges, 3)]],
        ];

        foreach ($cases as $key => $route) {
            $this->assertArrayHasKey($key, $this->validationErrors(fn () => $this->addVersion($route)), $key);
            $this->assertSame($before, $this->rows());
        }
    }

    public function test_a_shape_that_violates_the_definition_rules_is_rolled_back(): void
    {
        $this->createFlow();
        $before = $this->rows();
        [$nodes, $edges] = $this->r1();

        $this->assertThrows(fn () => $this->addVersion([$nodes, array_slice($edges, 0, 6)]), RouteDefinitionViolated::class);
        $this->assertSame($before, $this->rows());
    }

    public function test_a_missing_flow_is_not_found(): void
    {
        $this->assertThrows(fn () => app(AddRouteVersion::class)->execute('no_such_flow', CarbonImmutable::parse('2026-01-01'), ...$this->r3()), ModelNotFoundException::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function rows(): array
    {
        return [
            'versions' => WorkflowFlowVersion::query()->orderBy('id')->get(['id', 'end_date'])->toArray(),
            'nodes' => WorkflowRouteNode::query()->count(),
            'edges' => WorkflowRouteEdge::query()->count(),
            'send_backs' => WorkflowRouteSendBack::query()->count(),
        ];
    }
}

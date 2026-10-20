<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 計画の進め方のうち分岐・合流・同期(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.2〜2.4、同時実行と検査.md 14 節の「進め方」の分岐・合流)。
 * 期待は設計の手順から書く。各操作の後に不変の条件(2.7)を確かめる。申請者 = u0001(東京営業課)、課長 = u0002、部長 = u0003。
 */
final class CaseRoutePlannerBranchTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsRoutePlans;

    public function test_r1_with_a_large_budget_goes_through_the_manager(): void
    {
        $case = $this->caseOf($this->addVersion($this->r1()), values: ['budget' => 500]);

        $plan = $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->assertSame(['apply' => 'done', 'section_chief' => 'waiting[u0002]', 'budget' => 'unreached', 'manager' => 'unreached', 'budget_end' => 'unreached', 'council' => 'unreached', 'owner' => 'unreached'], $this->shape($case));
        $this->assertSame([$this->labelIds($case)['section_chief'] => [$this->seededUser('u0002')->id]], $plan->reachedNodes());
        $this->assertRouteInvariants($case);

        $this->step($case, 'section_chief', WorkflowAction::Approve, 'u0002', ['council' => ['u0004', 'u0010', 'u0009'], 'owner' => ['u0007']]);
        $this->assertSame(['apply' => 'done', 'section_chief' => 'done', 'budget' => 'passed', 'manager' => 'waiting[u0003]', 'budget_end' => 'unreached', 'council' => 'unreached', 'owner' => 'unreached'], $this->shape($case));
        $this->assertSame(['manager' => true, 'budget_end' => false], $this->takenOf($case, 'budget'));
        $this->assertRouteInvariants($case);

        $this->step($case, 'manager', WorkflowAction::Approve, 'u0003');
        $this->assertSame(['budget_end' => 'passed', 'council' => 'running', 'council.1' => 'waiting[u0004]', 'council.2' => 'waiting[u0010]', 'council.3' => 'waiting[u0009]', 'owner' => 'unreached'], array_slice($this->shape($case), 4));
        $this->assertRouteInvariants($case);
    }

    public function test_r1_with_a_small_budget_skips_the_manager_and_the_join_passes(): void
    {
        $case = $this->caseOf($this->addVersion($this->r1()), values: ['budget' => 100]);
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

        $plan = $this->step($case, 'section_chief', WorkflowAction::Approve, 'u0002', ['council' => ['u0004', 'u0010'], 'owner' => ['u0007']]);

        $this->assertSame(['apply' => 'done', 'section_chief' => 'done', 'budget' => 'passed', 'manager' => 'skipped', 'budget_end' => 'passed', 'council' => 'running', 'council.1' => 'waiting[u0004]', 'council.2' => 'waiting[u0010]', 'owner' => 'unreached'], $this->shape($case));
        $this->assertSame(['manager' => false, 'budget_end' => true], $this->takenOf($case, 'budget'));
        $this->assertRouteInvariants($case);
        $ids = $this->labelIds($case);
        $changes = array_column(WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail()->node_changes ?? [], null, 'node');
        $expected = [$ids['budget'], $ids['manager'], $ids['budget_end'], $ids['council'], $ids['council.1'], $ids['council.2']];
        sort($expected);
        $this->assertSame($expected, array_keys($changes), 'node_changes = 操作するノード以外の変えたノード(足した展開の行を含む。id の昇順)');
        $this->assertSame(['unreached', null, []], [$changes[$ids['council.1']]['state'], $changes[$ids['council.1']]['history'], $changes[$ids['council.1']]['targets']]);
        $this->assertEquals([$this->edgeId($case, 'budget', 'manager') => null, $this->edgeId($case, 'budget', 'budget_end') => null], $changes[$ids['budget']]['taken']);
        $this->assertSame([$ids['council.1'] => [$this->seededUser('u0004')->id], $ids['council.2'] => [$this->seededUser('u0010')->id]], $plan->reachedNodes($this->writtenChildIds), '到達したノード = 操作の後の現在のノードで、前は現在のノードでなかったもの');
    }

    public function test_the_result_does_not_depend_on_the_order_of_the_branch_edges(): void
    {
        [$nodes, $edges] = $this->r1();
        $edges[2] = new RouteEdgeInput('budget', 'manager', 2, '予算 300 万円以上', $edges[2]->condition);
        $edges[3] = new RouteEdgeInput('budget', 'budget_end', 1, '何もしない', $edges[3]->condition);
        $case = $this->caseOf($this->addVersion([$nodes, array_values($edges)]), values: ['budget' => 100]);
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

        $this->step($case, 'section_chief', WorkflowAction::Approve, 'u0002', ['council' => ['u0004', 'u0010'], 'owner' => ['u0007']]);

        $this->assertSame(['budget' => 'passed', 'manager' => 'skipped', 'budget_end' => 'passed', 'council' => 'running'], array_slice($this->shape($case), 2, 4));
        $this->assertRouteInvariants($case);
    }

    public function test_when_two_edges_are_true_both_branches_are_reached_and_the_join_waits_for_both(): void
    {
        $case = $this->caseOf($this->addVersion($this->twoWayBranch(['ge', 1], ['ge', 1])), values: ['budget' => 100]);

        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->assertSame(['apply' => 'done', 'br' => 'passed', 'a' => 'waiting[u0002]', 'b' => 'waiting[u0003]', 'e' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertRouteInvariants($case);

        $this->step($case, 'a', WorkflowAction::Approve, 'u0002');
        $this->assertSame(['a' => 'done', 'b' => 'waiting[u0003]', 'e' => 'unreached'], array_slice($this->shape($case), 2, 3));
        $this->step($case, 'b', WorkflowAction::Approve, 'u0003');
        $this->assertSame(['e' => 'passed', 'final' => 'waiting[u0010]'], array_slice($this->shape($case), 4));
        $this->assertRouteInvariants($case);
    }

    public function test_when_every_condition_is_false_the_lookahead_rejects_and_nothing_is_written(): void
    {
        $case = $this->caseOf($this->addVersion($this->r1()), values: []);
        $before = $this->shape($case);

        $errors = $this->validationErrors(fn () => $this->step($case, 'apply', WorkflowAction::Apply, 'u0001'));

        $this->assertSame(['case' => '分岐「予算」の条件に該当するルートがないため、申請できません'], $errors);
        $this->assertSame($before, $this->shape($case));
        $this->assertSame(0, WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->count());
    }

    public function test_skipping_a_false_branch_does_not_expand_its_nodes(): void
    {
        $case = $this->caseOf($this->addVersion([
            [$this->applyNode(), new RouteNodeInput('br', '分岐', WorkflowNodeKind::BranchStart, closingCode: 'e'), $this->approveNode('x1', 'u0002'), $this->approveNode('x2', 'u0015'), $this->approveNode('y', 'u0003'), new RouteNodeInput('e', '分岐終了', WorkflowNodeKind::BranchEnd)],
            [new RouteEdgeInput('apply', 'br'), $this->condition('br', 'x1', 1, ['ge', 300]), $this->condition('br', 'y', 2, ['lt', 300]), new RouteEdgeInput('x1', 'x2'), new RouteEdgeInput('x2', 'e'), new RouteEdgeInput('y', 'e')],
        ]), values: ['budget' => 100]);

        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

        $this->assertSame(['apply' => 'done', 'br' => 'passed', 'x1' => 'skipped', 'x2' => 'skipped', 'y' => 'waiting[u0003]', 'e' => 'unreached'], $this->shape($case));
        $this->assertRouteInvariants($case);
    }

    public function test_a_nested_branch_in_a_false_section_is_skipped_whole(): void
    {
        $case = $this->caseOf($this->addVersion([
            [$this->applyNode(), new RouteNodeInput('outer', '外', WorkflowNodeKind::BranchStart, closingCode: 'outer_end'), $this->approveNode('a', 'u0002'),
                new RouteNodeInput('inner', '内', WorkflowNodeKind::BranchStart, closingCode: 'inner_end'), $this->approveNode('c', 'u0003'), $this->approveNode('d', 'u0004'),
                new RouteNodeInput('inner_end', '内の終了', WorkflowNodeKind::BranchEnd), new RouteNodeInput('outer_end', '外の終了', WorkflowNodeKind::BranchEnd), $this->approveNode('final', 'u0010')],
            [new RouteEdgeInput('apply', 'outer'), $this->condition('outer', 'a', 1, ['ge', 300]), $this->condition('outer', 'inner', 2, ['lt', 300]),
                $this->condition('inner', 'c', 1, ['ge', 0]), $this->condition('inner', 'd', 2, ['ge', 0]), new RouteEdgeInput('c', 'inner_end'), new RouteEdgeInput('d', 'inner_end'),
                new RouteEdgeInput('a', 'outer_end'), new RouteEdgeInput('inner_end', 'outer_end'), new RouteEdgeInput('outer_end', 'final')],
        ]), values: ['budget' => 500]);

        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

        $this->assertSame(['apply' => 'done', 'outer' => 'passed', 'a' => 'waiting[u0002]', 'inner' => 'skipped', 'c' => 'skipped', 'd' => 'skipped', 'inner_end' => 'skipped', 'outer_end' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertSame(['c' => null, 'd' => null], $this->takenOf($case, 'inner'));
        $this->assertRouteInvariants($case);
    }

    public function test_r2_the_sync_waits_for_both_branches(): void
    {
        $case = $this->caseOf($this->addVersion($this->r2()));

        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->assertSame(['apply' => 'done', 'sync' => 'passed', 'a1' => 'waiting[u0002]', 'a2' => 'unreached', 'b1' => 'waiting[u0004]', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertRouteInvariants($case);

        $this->step($case, 'b1', WorkflowAction::Approve, 'u0004');
        $this->assertSame(['a1' => 'waiting[u0002]', 'a2' => 'unreached', 'b1' => 'done', 'sync_end' => 'unreached'], array_slice($this->shape($case), 2, 4));
        $this->step($case, 'a1', WorkflowAction::Approve, 'u0002');
        $this->assertSame(['a2' => 'waiting[u0003]', 'b1' => 'done', 'sync_end' => 'unreached'], array_slice($this->shape($case), 3, 3));
        $this->assertRouteInvariants($case);

        $this->step($case, 'a2', WorkflowAction::Approve, 'u0003');
        $this->assertSame(['sync_end' => 'passed', 'final' => 'waiting[u0010]'], array_slice($this->shape($case), 5));
        $this->step($case, 'final', WorkflowAction::Approve, 'u0010');
        $this->assertSame(['completed', 'approved'], $this->completionOf($case));
        $this->assertRouteInvariants($case);
    }

    public function test_a_branch_without_nodes_inside_a_sync_does_not_stop_it(): void
    {
        $case = $this->caseOf($this->addVersion([
            [$this->applyNode(), new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'), $this->approveNode('x', 'u0002'),
                new RouteNodeInput('br', '分岐', WorkflowNodeKind::BranchStart, closingCode: 'e'), $this->approveNode('y', 'u0003'), new RouteNodeInput('e', '分岐終了', WorkflowNodeKind::BranchEnd),
                new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd), $this->approveNode('final', 'u0010')],
            [new RouteEdgeInput('apply', 'sync'), new RouteEdgeInput('sync', 'x'), new RouteEdgeInput('sync', 'br'), $this->condition('br', 'e', 1, ['lt', 300]), $this->condition('br', 'y', 2, ['ge', 300]),
                new RouteEdgeInput('y', 'e'), new RouteEdgeInput('x', 'sync_end'), new RouteEdgeInput('e', 'sync_end'), new RouteEdgeInput('sync_end', 'final')],
        ]), values: ['budget' => 100]);

        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->assertSame(['apply' => 'done', 'sync' => 'passed', 'x' => 'waiting[u0002]', 'br' => 'passed', 'y' => 'skipped', 'e' => 'passed', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->shape($case));

        $this->step($case, 'x', WorkflowAction::Approve, 'u0002');
        $this->assertSame(['sync_end' => 'passed', 'final' => 'waiting[u0010]'], array_slice($this->shape($case), 6));
        $this->assertRouteInvariants($case);
    }

    /**
     * 申請 → 分岐 br(a = u0002 / b = u0003)→ e → final(u0010)。
     *
     * @param  array{string, int}  $toA
     * @param  array{string, int}  $toB
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function twoWayBranch(array $toA, array $toB): array
    {
        return [
            [$this->applyNode(), new RouteNodeInput('br', '分岐', WorkflowNodeKind::BranchStart, closingCode: 'e'), $this->approveNode('a', 'u0002'), $this->approveNode('b', 'u0003'), new RouteNodeInput('e', '分岐終了', WorkflowNodeKind::BranchEnd), $this->approveNode('final', 'u0010')],
            [new RouteEdgeInput('apply', 'br'), $this->condition('br', 'a', 1, $toA), $this->condition('br', 'b', 2, $toB), new RouteEdgeInput('a', 'e'), new RouteEdgeInput('b', 'e'), new RouteEdgeInput('e', 'final')],
        ];
    }

    /**
     * 予算の条件つきの分岐の辺。
     *
     * @param  array{string, int}  $comparison  [演算子, 値]
     */
    private function condition(string $from, string $to, int $sortKey, array $comparison): RouteEdgeInput
    {
        return new RouteEdgeInput($from, $to, $sortKey, "{$from}→{$to}", ['all' => [['field' => 'budget', 'op' => $comparison[0], 'value' => $comparison[1]]]]);
    }

    /**
     * 分岐開始から出る辺の taken(先のコード → 値)。
     *
     * @return array<string, bool|null>
     */
    private function takenOf(WorkflowCase $case, string $branchLabel): array
    {
        $labels = array_flip($this->labelIds($case));

        return WorkflowCaseEdge::query()->where('from_workflow_case_node_id', $this->labelIds($case)[$branchLabel])->orderBy('sort_key')->get()
            ->mapWithKeys(fn (WorkflowCaseEdge $edge) => [$labels[$edge->to_workflow_case_node_id] => $edge->taken])->all();
    }

    private function edgeId(WorkflowCase $case, string $from, string $to): int
    {
        $ids = $this->labelIds($case);

        return WorkflowCaseEdge::query()->where('from_workflow_case_node_id', $ids[$from])->where('to_workflow_case_node_id', $ids[$to])->sole()->id;
    }

    /**
     * @return array{string, string|null}
     */
    private function completionOf(WorkflowCase $case): array
    {
        $case = WorkflowCase::query()->findOrFail($case->id);

        return [$case->state->value, $case->completion_reason?->value];
    }
}

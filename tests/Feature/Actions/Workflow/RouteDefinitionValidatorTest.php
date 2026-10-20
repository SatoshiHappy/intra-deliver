<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RouteDefinitionValidator;
use App\Actions\Workflow\RouteDefinitionViolated;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\RouteViolation;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の検査(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節・8 節の「定義の検査のテスト」): 規則ごとに外れる最小の形を 1 つ以上。
 * 形は形つきの版を作る業務処理で書き、書いた後の検査の違反({@see RouteDefinitionViolated})で確かめる(書く前の入力の確かめが止める形 = 条件の JSON は、
 * 書いた行を変えてから検査を直に呼ぶ)。違反は「規則(関わるノードのコード)」の文字列で比べる。初期データの版とルートの見本 R1〜R3 は違反が無い。
 */
final class RouteDefinitionValidatorTest extends TransactionalTestCase
{
    use BuildsRouteVersions;

    public function test_the_route_samples_and_the_seeded_versions_pass(): void
    {
        foreach ([$this->r1(), $this->r2(), $this->r3()] as $index => $route) {
            $this->addVersion($route, '2026-0'.($index + 1).'-01');
        }

        foreach (WorkflowFlowVersion::query()->pluck('id') as $versionId) {
            $this->assertSame([], app(RouteDefinitionValidator::class)->violations((int) $versionId), "版 {$versionId}");
        }
    }

    public function test_g2_the_last_node_is_exactly_one_of_the_terminal_kinds(): void
    {
        $this->assertContains('G-2(apply)', $this->violations([$this->applyNode()], []));
        $this->assertContains('G-2(a, b, e)', $this->violations(
            [$this->applyNode(), $this->routing('s', WorkflowNodeKind::SyncStart, 'e'), $this->approveNode('a', 'u0002'), $this->approveNode('b', 'u0003'), $this->routing('e', WorkflowNodeKind::SyncEnd)],
            $this->chain(['apply', 's'], ['s', 'a'], ['s', 'b']),
        ));
    }

    public function test_g3_every_node_is_reachable_and_every_edge_points_forward(): void
    {
        $this->assertContains('G-3(b)', $this->violations([$this->applyNode(), $this->approveNode('a', 'u0002'), $this->approveNode('b', 'u0003')], $this->chain(['apply', 'a'])));
        $this->assertContains('G-3(b, a)', $this->violations([$this->applyNode(), $this->approveNode('a', 'u0002'), $this->approveNode('b', 'u0003')], $this->chain(['apply', 'b'], ['b', 'a'])));
    }

    public function test_g4_edge_counts(): void
    {
        $this->assertContains('G-4(br)', $this->violations(
            [$this->applyNode(), $this->routing('br', WorkflowNodeKind::BranchStart, 'e'), $this->approveNode('a', 'u0002'), $this->routing('e', WorkflowNodeKind::BranchEnd)],
            [new RouteEdgeInput('apply', 'br'), $this->branchEdge('br', 'a', 1), new RouteEdgeInput('a', 'e')],
        ));
        $this->assertContains('G-4(a)', $this->violations(
            [$this->applyNode(), $this->approveNode('a', 'u0002'), $this->approveNode('b', 'u0003'), $this->approveNode('c', 'u0004')],
            $this->chain(['apply', 'a'], ['a', 'b'], ['a', 'c']),
        ));
    }

    public function test_g5_the_section_shape(): void
    {
        $this->assertContains('G-5(br, e)', $this->violations(...$this->branch(WorkflowNodeKind::SyncEnd)), '対が分岐開始と同期終了');
        [$nodes, $edges] = $this->branch(WorkflowNodeKind::BranchEnd);
        $leaving = [...array_slice($nodes, 0, 4), $this->approveNode('c', 'u0004'), $nodes[4], $this->approveNode('f', 'u0010')];
        $this->assertContains('G-5(br, e)', $this->violations($leaving, [...$edges, new RouteEdgeInput('b', 'c'), new RouteEdgeInput('c', 'f'), new RouteEdgeInput('e', 'f')]), '区間から外へ出る辺');
        $crossing = $this->violations(
            [$this->applyNode(), $this->routing('s1', WorkflowNodeKind::SyncStart, 'e1'), $this->approveNode('a', 'u0002'), $this->routing('s2', WorkflowNodeKind::SyncStart, 'e2'),
                $this->approveNode('b', 'u0003'), $this->approveNode('c', 'u0004'), $this->routing('e1', WorkflowNodeKind::SyncEnd), $this->routing('e2', WorkflowNodeKind::SyncEnd), $this->approveNode('f', 'u0010')],
            $this->chain(['apply', 's1'], ['s1', 'a'], ['s1', 's2'], ['s2', 'b'], ['s2', 'c'], ['a', 'e1'], ['b', 'e1'], ['c', 'e2'], ['e1', 'e2'], ['e2', 'f']),
        );
        $this->assertNotSame([], array_filter($crossing, fn (string $violation) => str_starts_with($violation, 'G-5(')), '交わる 2 つの区間');
    }

    public function test_g6_branch_edges_have_a_name_and_a_condition_of_the_rule_shape_and_other_edges_do_not(): void
    {
        [$nodes, $edges] = $this->branch(WorkflowNodeKind::BranchEnd);
        $edges[2] = new RouteEdgeInput('br', 'b', 2);
        $this->assertContains('G-6(br, b)', $this->violations($nodes, array_values($edges)), '条件の無い分岐の辺');
        $named = $this->branch(WorkflowNodeKind::BranchEnd);
        $named[1][0] = new RouteEdgeInput('apply', 'br', 0, '名前', ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 1]]]);
        $this->assertContains('G-6(apply, br)', $this->violations(...$named), '分岐でない辺の条件');

        $versionId = $this->addVersion($this->r1());
        WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->where('sort_key', 1)->update(['condition' => json_encode(['any' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]])]);
        $this->assertSame(['G-6(budget, manager)'], $this->strings(app(RouteDefinitionValidator::class)->violations($versionId)), '形の違う条件の JSON');
    }

    public function test_g7_a_fixed_target_is_a_selection_subject_of_any_and_eq(): void
    {
        $all = AuthzSubjectGroup::factory()->createOne(['purpose' => SubjectGroupPurpose::Selection, 'combination' => SubjectCombination::All]);
        $node = new RouteNodeInput('a', 'a', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, subjectGroupId: $all->id);

        $this->assertContains('G-7(a)', $this->violations([$this->applyNode(), $node], $this->chain(['apply', 'a'])));
    }

    public function test_g9_the_previous_actor_base_is_not_placed_right_after_a_join(): void
    {
        [$nodes, $edges] = $this->branch(WorkflowNodeKind::BranchEnd);
        $previous = $this->previousNode('p');
        $this->assertContains('G-9(p)', $this->violations([...$nodes, $previous], [...$edges, new RouteEdgeInput('e', 'p')]), '合流の直後');

        $zero = new RouteNodeInput('z', 'z', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, designatorCode: 'a', minAssignees: 0, maxAssignees: 1);
        $this->assertContains('G-9(p, z)', $this->violations([...$nodes, $zero, $previous], [...$edges, new RouteEdgeInput('e', 'z'), new RouteEdgeInput('z', 'p')]), '最小 0 の動的承認を挟む');

        $council = new RouteNodeInput('council', 'council', WorkflowNodeKind::Parallel, WorkflowTargetKind::Designated, designatorCode: 'a', minAssignees: 1, maxAssignees: 2, decisionRule: WorkflowDecisionRule::All);
        $this->assertContains('G-9(p)', $this->violations([...$nodes, $council, $previous], [...$edges, new RouteEdgeInput('e', 'council'), new RouteEdgeInput('council', 'p')]), '縦配置の直後');

        $this->assertSame([], $this->violations([$this->applyNode(), $this->approveNode('a', 'u0002'), $previous], $this->chain(['apply', 'a'], ['a', 'p'])), '承認の直後は置ける');
    }

    public function test_g9_through_a_branch_start(): void
    {
        $nodes = [$this->applyNode(), $this->approveNode('x', 'u0002'), $this->approveNode('y', 'u0003'), $this->routing('j', WorkflowNodeKind::SyncEnd),
            $this->routing('br', WorkflowNodeKind::BranchStart, 'e'), $this->previousNode('p'), $this->approveNode('b', 'u0004'), $this->routing('e', WorkflowNodeKind::BranchEnd)];
        $syncStart = $this->routing('s', WorkflowNodeKind::SyncStart, 'j');
        $edges = [...$this->chain(['apply', 's'], ['s', 'x'], ['s', 'y'], ['x', 'j'], ['y', 'j'], ['j', 'br'], ['p', 'e'], ['b', 'e']), $this->branchEdge('br', 'p', 1), $this->branchEdge('br', 'b', 2)];

        $this->assertContains('G-9(p)', $this->violations([$nodes[0], $syncStart, ...array_slice($nodes, 1)], $edges));
    }

    public function test_g10_the_designator_is_an_approval_on_every_path_before_the_node(): void
    {
        [$nodes, $edges] = $this->branch(WorkflowNodeKind::BranchEnd);
        $designatedBy = fn (string $designator, string $code = 'y') => new RouteNodeInput($code, $code, WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, designatorCode: $designator, minAssignees: 1, maxAssignees: 1);

        $this->assertContains('G-10(y, a)', $this->violations([...$nodes, $designatedBy('a')], [...$edges, new RouteEdgeInput('e', 'y')]), '通らない枝にある');
        $this->assertContains('G-10(y, x)', $this->violations([$this->applyNode(), $designatedBy('x'), $this->approveNode('x', 'u0002')], $this->chain(['apply', 'y'], ['y', 'x'])), '後ろにある');
        $council = new RouteNodeInput('council', 'council', WorkflowNodeKind::Parallel, WorkflowTargetKind::Designated, designatorCode: 'a', minAssignees: 1, maxAssignees: 2, decisionRule: WorkflowDecisionRule::All);
        $this->assertContains('G-10(y, council)', $this->violations([$this->applyNode(), $this->approveNode('a', 'u0002'), $council, $designatedBy('council')], $this->chain(['apply', 'a'], ['a', 'council'], ['council', 'y'])), '縦配置である');
        $zero = new RouteNodeInput('z', 'z', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, designatorCode: 'a', minAssignees: 0, maxAssignees: 1);
        $this->assertContains('G-10(y, z)', $this->violations([$this->applyNode(), $this->approveNode('a', 'u0002'), $zero, $designatedBy('z')], $this->chain(['apply', 'a'], ['a', 'z'], ['z', 'y'])), '最小 0 の動的承認である');
    }

    public function test_g11_a_send_back_target_is_an_ancestor_of_the_source(): void
    {
        $this->assertContains('G-11(a, b)', $this->violations([$this->applyNode(), $this->approveNode('a', 'u0002', ['b']), $this->approveNode('b', 'u0003')], $this->chain(['apply', 'a'], ['a', 'b'])), '後ろのノード');
        [$nodes, $edges] = $this->branch(WorkflowNodeKind::BranchEnd);
        $nodes[3] = $this->approveNode('b', 'u0003', ['a']);
        $this->assertContains('G-11(b, a)', $this->violations(array_values($nodes), $edges), 'ほかの枝のノード');
    }

    /**
     * 申請 → 分岐開始 br(対 = e)→ 承認 a / 承認 b → e。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function branch(WorkflowNodeKind $closingKind): array
    {
        return [
            [$this->applyNode(), $this->routing('br', WorkflowNodeKind::BranchStart, 'e'), $this->approveNode('a', 'u0002'), $this->approveNode('b', 'u0003'), $this->routing('e', $closingKind)],
            [new RouteEdgeInput('apply', 'br'), $this->branchEdge('br', 'a', 1), $this->branchEdge('br', 'b', 2), new RouteEdgeInput('a', 'e'), new RouteEdgeInput('b', 'e')],
        ];
    }

    private function routing(string $code, WorkflowNodeKind $kind, ?string $closingCode = null): RouteNodeInput
    {
        return new RouteNodeInput($code, $code, $kind, closingCode: $closingCode);
    }

    private function previousNode(string $code): RouteNodeInput
    {
        return new RouteNodeInput($code, $code, WorkflowNodeKind::Approve, WorkflowTargetKind::Relative, relativeBase: WorkflowRelativeBase::Previous, relativeScope: WorkflowRelativeScope::Own);
    }

    private function branchEdge(string $from, string $to, int $sortKey): RouteEdgeInput
    {
        return new RouteEdgeInput($from, $to, $sortKey, "枝 {$to}", ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => $sortKey]]]);
    }

    /**
     * 版を足したときの違反(無ければ空)。
     *
     * @param  list<RouteNodeInput>  $nodes
     * @param  list<RouteEdgeInput>  $edges
     * @return list<string>
     */
    private function violations(array $nodes, array $edges): array
    {
        try {
            $this->addVersion([$nodes, $edges]);
        } catch (RouteDefinitionViolated $violated) {
            return $this->strings($violated->violations);
        }

        return [];
    }

    /**
     * @param  list<RouteViolation>  $violations
     * @return list<string>
     */
    private function strings(array $violations): array
    {
        return array_map(fn (RouteViolation $violation) => (string) $violation, $violations);
    }
}

<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 計画の進め方のうち合議・横配置・指定 0 人・完了の片付け(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.5・2.6、同時実行と検査.md 14 節の「進め方」)。
 * 期待は設計の手順から書く。各操作の後に不変の条件(2.7)を確かめる。
 */
final class CaseRoutePlannerExpansionTest extends TransactionalTestCase
{
    use RunsRoutePlans;

    /** 足した版の数(版の開始日を月ごとに進める) */
    private int $versionMonth = 0;

    public function test_r1_the_council_passes_on_the_second_approval_and_waits_after_a_rejection(): void
    {
        $case = $this->councilCase(['u0004', 'u0010', 'u0009']);

        $this->step($case, 'council.1', WorkflowAction::Approve, 'u0004');
        $this->assertSame(['council' => 'running', 'council.1' => 'done', 'council.2' => 'waiting[u0010]', 'council.3' => 'waiting[u0009]', 'owner' => 'unreached'], $this->tail($case));
        $this->step($case, 'council.2', WorkflowAction::Reject, 'u0010');
        $this->assertSame(['council' => 'running', 'council.1' => 'done', 'council.2' => 'done', 'council.3' => 'waiting[u0009]', 'owner' => 'unreached'], $this->tail($case));
        $this->assertSame('active', WorkflowCase::query()->findOrFail($case->id)->state->value);
        $this->assertRouteInvariants($case);

        $this->step($case, 'council.3', WorkflowAction::Approve, 'u0009');
        $this->assertSame(['council' => 'passed', 'council.1' => 'done', 'council.2' => 'done', 'council.3' => 'done', 'owner' => 'waiting[u0007]'], $this->tail($case));
        $this->assertRouteInvariants($case);

        $plan = $this->step($case, 'owner', WorkflowAction::Approve, 'u0007');
        $this->assertSame('approved', $plan->completion?->value);
        $this->assertRouteInvariants($case);
    }

    public function test_r1_when_the_council_passes_the_remaining_child_is_skipped(): void
    {
        $case = $this->councilCase(['u0004', 'u0010', 'u0009']);
        $this->step($case, 'council.3', WorkflowAction::Approve, 'u0009');

        $plan = $this->step($case, 'council.1', WorkflowAction::Approve, 'u0004');

        $this->assertSame(['council' => 'passed', 'council.1' => 'done', 'council.2' => 'skipped', 'council.3' => 'done', 'owner' => 'waiting[u0007]'], $this->tail($case));
        $this->assertSame([$this->labelIds($case)['owner'] => [$this->seededUser('u0007')->id]], $plan->reachedNodes());
        $this->assertSame([], WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $this->labelIds($case)['council.2'])->pluck('user_id')->all());
        $this->assertRouteInvariants($case);
    }

    public function test_r1_when_the_council_can_no_longer_pass_the_case_is_rejected_and_cleaned_up(): void
    {
        $case = $this->councilCase(['u0004', 'u0010', 'u0009']);
        $this->step($case, 'council.1', WorkflowAction::Reject, 'u0004');

        $plan = $this->step($case, 'council.2', WorkflowAction::Reject, 'u0010');

        $this->assertSame('rejected', $plan->completion?->value);
        $this->assertSame(['council' => 'skipped', 'council.1' => 'done', 'council.2' => 'done', 'council.3' => 'skipped', 'owner' => 'unreached'], $this->tail($case));
        $this->assertRouteInvariants($case);
    }

    public function test_r3_the_serial_children_are_reached_one_by_one_and_the_last_approval_passes_the_parent(): void
    {
        $case = $this->caseOf($this->addVersion($this->r3()));
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

        $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['line' => ['u0003', 'u0004', 'u0009']]);
        $this->assertSame(['apply' => 'done', 'chief' => 'done', 'line' => 'running', 'line.1' => 'waiting[u0003]', 'line.2' => 'unreached', 'line.3' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertRouteInvariants($case);

        $this->step($case, 'line.1', WorkflowAction::Approve, 'u0003');
        $this->assertSame(['line' => 'running', 'line.1' => 'done', 'line.2' => 'waiting[u0004]', 'line.3' => 'unreached', 'final' => 'unreached'], array_slice($this->shape($case), 2));
        $this->assertRouteInvariants($case);
        $this->step($case, 'line.2', WorkflowAction::Approve, 'u0004');
        $this->step($case, 'line.3', WorkflowAction::Approve, 'u0009');
        $this->assertSame(['line' => 'passed', 'line.1' => 'done', 'line.2' => 'done', 'line.3' => 'done', 'final' => 'waiting[u0010]'], array_slice($this->shape($case), 2));
        $this->assertRouteInvariants($case);
    }

    public function test_r3_a_rejection_of_a_serial_child_rejects_the_case(): void
    {
        $case = $this->caseOf($this->addVersion($this->r3()));
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['line' => ['u0003', 'u0004']]);

        $plan = $this->step($case, 'line.1', WorkflowAction::Reject, 'u0003');

        $this->assertSame('rejected', $plan->completion?->value);
        $this->assertSame(['line' => 'skipped', 'line.1' => 'done', 'line.2' => 'unreached', 'final' => 'unreached'], array_slice($this->shape($case), 2));
        $this->assertRouteInvariants($case);
    }

    /**
     * 横配置の子の承認終了: 案件 = 完了(承認終了)、展開中の親を省き、到達していない後の子と後のノードはそのまま(2.6 の完了の片付け)。
     */
    public function test_r3_approving_early_a_serial_child_completes_the_case_and_skips_the_parent(): void
    {
        $case = $this->caseOf($this->addVersion($this->r3()));
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['line' => ['u0003', 'u0004', 'u0009']]);
        $this->step($case, 'line.1', WorkflowAction::Approve, 'u0003');

        $plan = $this->step($case, 'line.2', WorkflowAction::ApproveEarly, 'u0004');

        $this->assertSame('approved_early', $plan->completion?->value);
        $this->assertSame(['line' => 'skipped', 'line.1' => 'done', 'line.2' => 'done', 'line.3' => 'unreached', 'final' => 'unreached'], array_slice($this->shape($case), 2));
        $this->assertSame([], $plan->reachedNodes());
        $this->assertRouteInvariants($case);
    }

    public function test_zero_assignees_skip_the_designated_node_and_the_route_goes_on(): void
    {
        foreach (['serial' => WorkflowNodeKind::Serial, 'parallel' => WorkflowNodeKind::Parallel, 'approve' => WorkflowNodeKind::Approve] as $label => $kind) {
            $case = $this->caseOf($this->addVersion($this->zeroRoute($kind, true), $this->nextStart()));
            $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

            $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['y' => []]);

            $this->assertSame(['apply' => 'done', 'chief' => 'done', 'y' => 'skipped', 'final' => 'waiting[u0010]'], $this->shape($case), $label);
            $this->assertRouteInvariants($case);
        }
    }

    public function test_the_last_node_skipped_by_zero_assignees_completes_the_case_as_approved(): void
    {
        $case = $this->caseOf($this->addVersion($this->zeroRoute(WorkflowNodeKind::Approve, false)));
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');

        $plan = $this->step($case, 'chief', WorkflowAction::Approve, 'u0002', ['y' => []]);

        $this->assertSame('approved', $plan->completion?->value);
        $this->assertSame(['apply' => 'done', 'chief' => 'done', 'y' => 'skipped'], $this->shape($case));
        $this->assertRouteInvariants($case);
    }

    public function test_completion_cleans_up_the_other_current_nodes(): void
    {
        $early = $this->caseOf($this->addVersion($this->r2(), $this->nextStart()));
        $this->step($early, 'apply', WorkflowAction::Apply, 'u0001');
        $plan = $this->step($early, 'a1', WorkflowAction::ApproveEarly, 'u0002');
        $this->assertSame('approved_early', $plan->completion?->value);
        $this->assertSame(['apply' => 'done', 'sync' => 'passed', 'a1' => 'done', 'a2' => 'unreached', 'b1' => 'skipped', 'sync_end' => 'unreached', 'final' => 'unreached'], $this->shape($early));
        $this->assertRouteInvariants($early);

        $rejected = $this->caseOf($this->addVersion($this->r2(), $this->nextStart()));
        $this->step($rejected, 'apply', WorkflowAction::Apply, 'u0001');
        $this->assertSame('rejected', $this->step($rejected, 'b1', WorkflowAction::Reject, 'u0004')->completion?->value);
        $this->assertSame(['a1' => 'skipped', 'b1' => 'done'], [...array_slice($this->shape($rejected), 2, 1), ...array_slice($this->shape($rejected), 4, 1)]);
        $this->assertRouteInvariants($rejected);

        $council = $this->councilCase(['u0004', 'u0010']);
        $this->assertSame('approved_early', $this->step($council, 'council.2', WorkflowAction::ApproveEarly, 'u0010')->completion?->value);
        $this->assertSame(['council' => 'skipped', 'council.1' => 'skipped', 'council.2' => 'done', 'owner' => 'unreached'], $this->tail($council));
        $this->assertRouteInvariants($council);
    }

    public function test_withdraw_completes_the_case_from_the_apply_node_waiting_for_reapplication(): void
    {
        $case = $this->caseOf($this->addVersion($this->r3()));
        $apply = WorkflowCaseNode::query()->findOrFail($this->labelIds($case)['apply']);
        $apply->update(['state' => WorkflowNodeState::Reapply, 'reached_at' => now()]);
        WorkflowCaseNodeTarget::factory()->createOne(['workflow_case_node_id' => $apply->id, 'user_id' => $this->seededUser('u0001')->id]);

        $plan = $this->step($case, 'apply', WorkflowAction::Withdraw, 'u0001');

        $this->assertSame('withdrawn', $plan->completion?->value);
        $this->assertSame(['apply' => 'done', 'chief' => 'unreached', 'line' => 'unreached', 'final' => 'unreached'], $this->shape($case));
        $this->assertRouteInvariants($case);
    }

    /**
     * R1(予算 100 = 部長を通らない)で、課長が合議の人と責任者 u0007 を指定して承認した後の案件(合議が展開中)。
     *
     * @param  list<string>  $councilCodes
     */
    private function councilCase(array $councilCodes): WorkflowCase
    {
        $case = $this->caseOf($this->addVersion($this->r1(), $this->nextStart()), values: ['budget' => 100]);
        $this->step($case, 'apply', WorkflowAction::Apply, 'u0001');
        $this->step($case, 'section_chief', WorkflowAction::Approve, 'u0002', ['council' => $councilCodes, 'owner' => ['u0007']]);

        return $case;
    }

    /**
     * 合議から後ろの形。
     *
     * @return array<string, string>
     */
    private function tail(WorkflowCase $case): array
    {
        return array_slice($this->shape($case), 5);
    }

    /**
     * 申請 → chief(u0002)→ y(処理の時に指定、最小 0)→(final(u0010))。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function zeroRoute(WorkflowNodeKind $kind, bool $withFinal): array
    {
        $designated = new RouteNodeInput('y', '指定', $kind, WorkflowTargetKind::Designated, designatorCode: 'chief', minAssignees: 0, maxAssignees: 3,
            decisionRule: $kind === WorkflowNodeKind::Parallel ? WorkflowDecisionRule::All : null);
        $nodes = [$this->applyNode(), $this->approveNode('chief', 'u0002'), $designated];

        return $withFinal
            ? [[...$nodes, $this->approveNode('final', 'u0010')], $this->chain(['apply', 'chief'], ['chief', 'y'], ['y', 'final'])]
            : [$nodes, $this->chain(['apply', 'chief'], ['chief', 'y'])];
    }

    /**
     * 同じテストで版を足し続けるときの次の開始日。
     */
    private function nextStart(): string
    {
        return sprintf('2026-%02d-01', ++$this->versionMonth);
    }
}

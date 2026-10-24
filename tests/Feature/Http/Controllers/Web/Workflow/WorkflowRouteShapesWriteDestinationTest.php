<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\ApplicantApprovalGuard;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 段 3a の「書き込み → 移り先の GET」(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 7 節、通しの回 T12 の表の組のうち、テストのフローに足した版の形の分):
 * 申請者承認防止のステップでの申請者の差戻しと引戻し、同期の 2 つの枝の引戻し・承認(node_id で片方)、横配置の子 1 の承認、最小 0 のステップへの 0 人の指定。
 * 前の状態は業務処理で作り({@see OperatesOnRoutes})、確かめる書き込みだけを HTTP で送って、移り先を GET する。期待は通しの回の表と設計から書いた。
 * 初期データの版 3 の組は {@see WorkflowRouteWriteDestinationTest}。
 */
final class WorkflowRouteShapesWriteDestinationTest extends TransactionalTestCase
{
    use FollowsCaseWrites;
    use OperatesOnRoutes;
    use UsesSeededCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 防止のステップ(処理対象者 = 申請者 u0001 と u0002)で申請者が差戻す → 詳細: 申請が再申請待ちで、申請者は再申請できる。
     */
    public function test_the_applicant_sends_back_on_a_preventing_step_and_can_reapply(): void
    {
        $this->addSalesRoute();
        $case = $this->applyOnRoute([])[0]->id;

        $this->assertLandsOnDetail($this->act('u0001', $case, 'send-back', ['node_id' => $this->nodeOf($case, 'sales'), 'send_back_node_id' => $this->nodeOf($case, 'apply')]), $case);

        $props = $this->detail('u0001', $case);
        $this->assertSame([['state' => 'reapply', 'name' => '申請'], true], [$props['case']['currentSummary'], $props['can']['reapply']]);
    }

    /**
     * 防止のステップで申請者が差戻した後の、その差戻しの引戻し → 元の画面(詳細)へ戻って 422 case、何も変わらない。詳細の引戻しのステップには同じ文言(通しの回の T02)。
     */
    public function test_the_applicant_cannot_pull_back_the_send_back_on_a_preventing_step(): void
    {
        $this->addSalesRoute();
        $case = $this->applyOnRoute([])[0];
        $this->sendBackOn($case, 'sales', 'u0001', 'apply');
        $applicantId = $this->seededUser('u0001')->id;
        $message = ApplicantApprovalGuard::onlyApplicantTargetsMessage('東京営業課', [$applicantId], $applicantId);
        $before = $this->snapshotOf($case->id);

        $this->act('u0001', $case->id, 'pull-back', ['node_id' => $this->nodeOf($case->id, 'sales')])
            ->assertStatus(302)->assertRedirect("/workflow/cases/{$case->id}")->assertSessionHasErrors(['case' => $message]);

        $this->assertSame($before, $this->snapshotOf($case->id));
        $props = $this->detail('u0001', $case->id);
        $this->assertSame([true, [['id' => $this->nodeOf($case->id, 'sales'), 'name' => '東京営業課', 'blockedReason' => $message, 'canAttach' => true, 'principalUserId' => null]]], [$props['can']['pullBack'], $props['pullBackNodes']]);
    }

    /**
     * 同期の 2 つの枝(a1 → a2、b1)の a1・b1 を同じ人が承認した後、node_id で b1 を引戻す → 詳細: b1 だけ承認待ちに戻り、引戻せるステップは a1 だけ。
     */
    public function test_a_person_with_two_pull_backable_branches_pulls_back_the_chosen_one(): void
    {
        $case = $this->caseOnBothBranches();
        $this->approve($case, 'a1', 'u0002');
        $this->approve($case, 'b1', 'u0002');

        $this->assertLandsOnDetail($this->act('u0002', $case->id, 'pull-back', ['node_id' => $this->nodeOf($case->id, 'b1')]), $case->id);

        $props = $this->detail('u0002', $case->id);
        $this->assertSame(['a1' => 'done', 'a2' => 'waiting', 'b1' => 'waiting'], $this->statesIn($props, $case->id, 'a1', 'a2', 'b1'));
        $this->assertSame(['a1'], array_column($props['pullBackNodes'], 'name'));
    }

    /**
     * 並列の 2 つのノード(a1・b1)の処理対象者が node_id で a1 を承認 → 詳細: もう片方(b1)は承認待ちで、操作できるステップに残る。
     */
    public function test_a_target_of_two_parallel_nodes_approves_the_chosen_one(): void
    {
        $case = $this->caseOnBothBranches();

        $this->assertLandsOnDetail($this->act('u0002', $case->id, 'approve', ['node_id' => $this->nodeOf($case->id, 'a1')]), $case->id);

        $props = $this->detail('u0002', $case->id);
        $this->assertSame(['a1' => 'done', 'b1' => 'waiting'], $this->statesIn($props, $case->id, 'a1', 'b1'));
        $this->assertSame([['b1', true]], array_map(fn (array $action) => [$action['name'], $action['approve']], $props['nodeActions']));
    }

    /**
     * 横配置(chief が 2 人を指定)の子 1 の承認 → 詳細: 子 2 が承認待ち。
     */
    public function test_approving_the_first_serial_child_reaches_the_second(): void
    {
        $this->addVersion($this->r3());
        $case = $this->applyOnRoute([])[0];
        $this->approve($case, 'chief', 'u0002', ['line' => ['u0003', 'u0004']]);

        $this->assertLandsOnDetail($this->act('u0003', $case->id, 'approve', ['node_id' => $this->nodeOf($case->id, 'line.1')]), $case->id);

        $this->assertSame(['line.1' => 'done', 'line.2' => 'waiting'], $this->statesIn($this->detail('u0003', $case->id), $case->id, 'line.1', 'line.2'));
    }

    /**
     * 最小 0 の最後のステップに 0 人を指定して承認 → 詳細: 指定されたステップは省略で、案件は完了(承認)。履歴の指定は人のいない指定(画面は「= 省略」= 通しの回の T08)。
     */
    public function test_designating_nobody_to_the_last_step_skips_it_and_completes_the_case(): void
    {
        $this->addVersion([[$this->applyNode(), $this->approveNode('chief', 'u0002', ['apply']),
            new RouteNodeInput('line', '順番の承認', WorkflowNodeKind::Serial, WorkflowTargetKind::Designated, designatorCode: 'chief', minAssignees: 0, maxAssignees: 3, sendBackCodes: ['apply', 'chief']),
        ], $this->chain(['apply', 'chief'], ['chief', 'line'])]);
        $case = $this->applyOnRoute([])[0]->id;

        $this->assertLandsOnDetail($this->act('u0002', $case, 'approve', ['node_id' => $this->nodeOf($case, 'chief'), 'assignments' => $this->assignmentsFor($case, ['line' => []])]), $case);

        $props = $this->detail('u0002', $case);
        $this->assertSame(['completed', 'approved', ['line' => 'skipped']], [$props['case']['state'], $props['case']['completionReason'], $this->statesIn($props, $case, 'line')]);
        $this->assertSame([['順番の承認', []]], $this->lastAssignments($props));
    }

    /**
     * 同期で 2 つの枝(a1(u0002)→ a2(u0003)、b1(u0002))を待ち、最後に final(u0010)の版の案件(R2 の b1 を u0002 にしたもの)。
     */
    private function caseOnBothBranches(): WorkflowCase
    {
        [$nodes, $edges] = $this->r2();
        $this->addVersion([array_map(fn (RouteNodeInput $node) => $node->code === 'b1' ? $this->approveNode('b1', 'u0002', ['apply']) : $node, $nodes), $edges]);

        return $this->applyOnRoute([])[0];
    }

    /**
     * 申請 → 東京営業課(申請者 u0001 と課長 u0002。申請者承認防止オン)→ final(u0010)の版。
     */
    private function addSalesRoute(): void
    {
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('sales', '東京営業課', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales'))], ['ja' => '東京営業課'], preventsApplicantApproval: true, sendBackCodes: ['apply']),
            $this->approveNode('final', 'u0010', ['apply', 'sales']),
        ], $this->chain(['apply', 'sales'], ['sales', 'final'])]);
    }
}

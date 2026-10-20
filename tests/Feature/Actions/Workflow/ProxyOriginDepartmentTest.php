<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Master\CreateDepartmentMembership;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\CasePlanRequest;
use App\Actions\Workflow\ReapplyCase;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;
use App\Support\Period;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 計画に渡す選んだ組織(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 3.2 節の「計画に渡す選んだ組織」):
 * 代理では実行者の選んだ組織で処理者の起点の組織を決めない(前処理者 = 申請者なら担当組織、ほかは主所属)。本人の操作は選んだ組織のまま。
 * 代理元 A = u0009(総務部が主所属、人事部の兼務)、代理先 B = u0002(人事部の兼務を足して人事部を選ぶ)。
 */
final class ProxyOriginDepartmentTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use RegistersProxies;
    use RunsWorkflowOperations;

    /**
     * 代理先 B = u0002(選んだ組織 = B の所属組織の人事部)が A = u0009(総務部が主所属、人事部の兼務)として承認すると、到達で書く起点の組織は A の主所属。
     * A 本人が人事部を選んで承認すると人事部のまま。
     */
    public function test_the_origin_department_of_an_approval_as_the_principal_is_the_main_department(): void
    {
        $this->createFlow(approvals: [[$this->userCondition('u0009')], [$this->userCondition('u0003')]]);
        [$a, $b] = $this->seededUsers('u0009', 'u0002');
        $humanResources = $this->joinHumanResources($b);
        $this->addProxy($a, $b);
        $byProxy = $this->applyAs($this->seededUser('u0001'));
        $byItself = $this->applyAs($this->seededUser('u0001'));
        $approve = fn (User $user, WorkflowCase $case, ?int $principal) => app(ApproveCase::class)->execute(
            $this->contextOf($user, $humanResources),
            $case->id,
            $this->revisionOf($case),
            $this->caseNode($case, 'approval1')->id,
            null,
            principalUserId: $principal,
        );

        $approve($b, $byProxy, $a->id);
        $approve($a, $byItself, null);

        $this->assertSame([$a->id, $this->departmentIdOf('general-affairs')], $this->reachOf($byProxy, 'approval2'));
        $this->assertSame([$a->id, $humanResources], $this->reachOf($byItself, 'approval2'));
    }

    /**
     * 申請者 A = u0009(総務部を選んで申請 = 担当組織 総務部)の再申請を、代理先 B(人事部を選ぶ)が A として行うと、起点の組織は担当組織。A 本人なら選んだ人事部。
     */
    public function test_the_origin_department_of_a_reapply_as_the_applicant_is_the_case_department(): void
    {
        $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
        [$a, $b, $approver] = $this->seededUsers('u0009', 'u0002', 'u0003');
        $humanResources = $this->joinHumanResources($b);
        $this->addProxy($a, $b, WorkflowProxyKind::Apply);
        $generalAffairs = $this->departmentIdOf('general-affairs');
        [$byProxy, $byItself] = [$this->applyAs($a), $this->applyAs($a)];

        foreach ([$byProxy, $byItself] as $case) {
            $this->sendBack($approver, $case, 'apply');
        }

        $input = $this->projectInput();
        $reapply = fn (User $user, WorkflowCase $case, ?int $principal) => app(ReapplyCase::class)
            ->execute($this->contextOf($user, $humanResources), $case->id, $this->revisionOf($case), $input,
                $principal);
        $reapply($b, $byProxy, $a->id);
        $reapply($a, $byItself, null);

        $departments = [$byProxy->refresh()->department_id, $byItself->refresh()->department_id];

        $this->assertSame([$generalAffairs, $generalAffairs], $departments);
        $this->assertSame(
            [[$a->id, $generalAffairs], [$a->id, $humanResources]],
            [$this->reachOf($byProxy, 'approval1'), $this->reachOf($byItself, 'approval1')],
        );
    }

    /**
     * 計画に渡す選んだ組織(差戻し・引戻しを含む操作の 1 か所 = CasePlanRequest::by): 処理者 = 実行者 → 選んだ組織、代理で前処理者 = 申請者 → 担当組織、
     * ほか → null(主所属)。
     */
    public function test_the_selected_department_given_to_the_plan_does_not_come_from_the_executor_of_a_proxy(): void
    {
        // 申請者 = 1(担当組織 10)、実行者 = 2(選んだ組織 20)、ほかの代理元 = 3
        $case = new WorkflowCase(['user_id' => 1, 'department_id' => 10]);
        $context = new AccessContext(2, null, 20, $this->operationDay(), AccessContext::LOCALE_CODE);
        $selected = fn (CaseActor $actor, WorkflowAction $action) => CasePlanRequest::by(
            $actor, $context, $case, 0, $action,
        )->selectedDepartmentId;

        $this->assertSame([20, 10, null, 10, null], [
            $selected(CaseActor::of(2), WorkflowAction::Approve),
            $selected(new CaseActor(1, 2), WorkflowAction::Reapply),
            $selected(new CaseActor(3, 2), WorkflowAction::SendBack),
            $selected(new CaseActor(1, 2), WorkflowAction::PullBack),
            $selected(new CaseActor(3, 2), WorkflowAction::PullBack),
        ]);
    }

    /**
     * 人事部の兼務の所属を足す(B の所属組織を人事部にして、B が人事部を選べるようにする)。
     *
     * @return int 人事部の id
     */
    private function joinHumanResources(User $user): int
    {
        $departmentId = $this->departmentIdOf('human-resources');
        // 期間は初期データの兼務(u0009 の人事部)と同じ(組織の有効期間の中)
        $memberships = DepartmentMembership::query()->where('department_id', $departmentId);
        $first = $memberships->oldest('start_date')->firstOrFail();
        $period = new Period($first->start_date->toImmutable(), null);
        app(CreateDepartmentMembership::class)->execute($user->id, $departmentId, $period, false, 99);

        return $departmentId;
    }

    /**
     * ノードの [到達の前処理者, 起点の組織]。
     *
     * @return array{int|null, int|null}
     */
    private function reachOf(WorkflowCase $case, string $code): array
    {
        $node = $this->caseNode($case, $code);

        return [$node->previous_user_id, $node->previous_department_id];
    }
}

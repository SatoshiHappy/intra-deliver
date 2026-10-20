<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\HoldCase;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowFlow;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 処理対象者の候補の入口の 403 を、処理者 P の候補(自分と、自分を承認の代理先とする代理元)のどれかで決める(docs の
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節の「指定」、README.md の 4.3 節)。案件は初期データの版 3(分岐 B)に u0001 が申請したもの
 * (課長 u0002 が合議と責任者を指定する)。代理先 u0010 は計画のフローの参照者なので、代理が効かなくても案件を見られる(404 でなく 403 になる)。
 */
final class AssigneeCandidatesProxyTest extends TransactionalTestCase
{
    use AppliesOnTheBranchRoute;
    use RegistersProxies;
    use UsesSeededCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 承認待ちの課長の処理対象者の行が u0002 だけで、u0010 が u0002 の承認の代理先なら、代理の項だけで当たる u0010 も候補を引ける。
     */
    public function test_the_approval_proxy_of_the_designator_gets_the_candidates(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->addProxy($this->user('u0002'), $this->user('u0010'));
        $this->actingAsUser('u0010');

        $users = $this->getJson($this->url($case))->assertOk()->json('users');

        $this->assertContains('u0009', array_column($users, 'code'));
    }

    /**
     * 代理が別のフローだけに効く・期限が切れた・申請の代理なら、u0010 は 403 のまま。
     */
    public function test_a_proxy_that_does_not_work_on_the_node_is_forbidden(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $otherFlowId = WorkflowFlow::query()->where('code', '<>', 'project_plan')->orderBy('id')->value('id');
        $this->assertIsInt($otherFlowId);
        $today = $this->branchToday()->toDateString();
        [$principal, $proxy] = [$this->user('u0002'), $this->user('u0010')];
        $this->actingAsUser('u0010');

        $statuses = [];

        foreach ([
            fn () => $this->addProxy($principal, $proxy, flowId: $otherFlowId),
            fn () => $this->addProxy($principal, $proxy, end: $today),
            fn () => $this->addProxy($principal, $proxy, WorkflowProxyKind::Apply),
        ] as $register) {
            $row = $register();
            $statuses[] = $this->getJson($this->url($case))->status();
            $row->delete();
        }

        $this->assertSame([403, 403, 403], $statuses);
    }

    /**
     * 保留中の課長(処理対象者 u0002・u0004、u0002 が保留)では、保留した u0002 と u0002 の承認の代理先 u0010 は候補を引け、
     * 行で入っているほかの処理対象者 u0004 は保留者側でないので 403。
     */
    public function test_on_a_held_designator_the_hold_side_gets_the_candidates_and_the_other_target_does_not(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $chief = $this->nodeIdOf($case, 'section_chief');
        $other = $this->branchUserId('u0004');
        WorkflowCaseNodeTarget::query()->create(['workflow_case_node_id' => $chief, 'user_id' => $other]);
        $revision = WorkflowCase::query()->findOrFail($case)->revision;
        app(HoldCase::class)->execute($this->branchContext('u0002'), $case, $revision, $chief, null);
        $this->addProxy($this->user('u0002'), $this->user('u0010'));

        $statuses = [];

        foreach (['u0002', 'u0010', 'u0004'] as $code) {
            $this->actingAsUser($code);
            $statuses[$code] = $this->getJson($this->url($case))->status();
        }

        $this->assertSame(['u0002' => 200, 'u0010' => 200, 'u0004' => 403], $statuses);
    }

    /**
     * 代理の代理は辿らない: u0010 が u0005 の承認の代理先で、u0005 が u0002 の承認の代理先(課長の行は u0002 だけ)なら、u0010 は 403。
     */
    public function test_a_proxy_of_a_proxy_is_forbidden(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->addProxy($this->user('u0005'), $this->user('u0010'));
        $this->addProxy($this->user('u0002'), $this->user('u0005'));
        $this->actingAsUser('u0010');

        $this->getJson($this->url($case))->assertForbidden();
    }

    private function url(int $caseId): string
    {
        $query = http_build_query(['node' => $this->nodeIdOf($caseId, 'council'), 'q' => 'u000']);

        return "/workflow/cases/{$caseId}/assignee-candidates?{$query}";
    }

    private function nodeIdOf(int $caseId, string $code): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', $code)->sole()->id;
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}

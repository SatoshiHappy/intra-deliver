<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use Carbon\CarbonImmutable;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\CopiesCases;
use Tests\Support\GrantsFlowPermissions;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件からの複製の閲覧の展開と代理(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/検査.md の 14 節の 12.3):
 * 未処理・処理済・参照・確認・管理の述語のそれぞれだけで閲覧できる人が 200。代理先として未処理・処理済の述語で閲覧できる本人が 200、
 * 本人だけが閲覧できる代理が 200、代理元だけが参照・確認・管理の述語で閲覧できる本人は 404(閲覧はログインしている人で見る)。
 */
final class CaseCopyVisibilityTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use CopiesCases;
    use GrantsFlowPermissions;
    use RegistersProxies;
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 述語のそれぞれだけで閲覧できる人(どの人も申請できる): 未処理(承認する人の待ち)・処理済(承認した後)・参照(フローの参照者)・
     * 確認(確認対象者の行)・管理(workflow-flow の read)が 200。どれにも当たらない人は 404。
     */
    public function test_each_visibility_predicate_alone_opens_the_copy(): void
    {
        [$u0001, $u0002, $u0005, $u0006, $u0007, $u0008] = $this->usersOf(
            'u0001', 'u0002', 'u0005', 'u0006', 'u0007', 'u0008',
        );
        $this->copyFlow([$u0001, $u0002, $u0005, $u0006, $u0007, $u0008]);
        $pending = $this->sourceCase($u0001);
        $processed = $this->sourceCase($u0001);
        $this->operate(ApproveCase::class, $u0002, $processed);
        $ids = [$pending->id, $processed->id];
        $this->assertSame([404, 404], $this->statusesAs($u0005, $ids));

        $this->referTo($u0005);
        $this->confirmTarget($pending, $u0006);
        $this->grantFlow($u0007, self::COPY_FLOW, WorkflowFlowAction::Read);

        $this->assertSame([200, 200], $this->statusesAs($u0002, $ids));
        $this->assertSame([200, 200], $this->statusesAs($u0005, $ids));
        $this->assertSame([200, 404], $this->statusesAs($u0006, $ids));
        $this->assertSame([200, 200], $this->statusesAs($u0007, $ids));
        $this->assertSame([404, 404], $this->statusesAs($u0008, $ids));
    }

    /**
     * 本人が代理元(承認する人)の代理先として、未処理の述語(代理元の待ち)・処理済の述語(代理元が承認した後)だけで閲覧できる → 200。
     */
    public function test_the_proxy_of_the_approver_opens_pending_and_processed_cases(): void
    {
        [$u0001, $u0002, $u0005, $u0008] = $this->usersOf('u0001', 'u0002', 'u0005', 'u0008');
        $flowId = $this->copyFlow([$u0001, $u0005, $u0008]);
        $pending = $this->sourceCase($u0001);
        $processed = $this->sourceCase($u0001);
        $this->operate(ApproveCase::class, $u0002, $processed);
        $ids = [$pending->id, $processed->id];
        $this->assertSame([404, 404], $this->statusesAs($u0005, $ids));

        $this->addProxy($u0002, $u0005, WorkflowProxyKind::Approve, $flowId);

        $this->assertSame([200, 200], $this->statusesAs($u0005, $ids));
        $this->assertSame([404, 404], $this->statusesAs($u0008, $ids));
    }

    /**
     * 代理で、本人(申請できない)は閲覧でき代理元(申請できる)は閲覧できない → 200。既定の申請者は代理元。
     */
    public function test_the_proxy_who_sees_the_case_opens_it_for_the_principal(): void
    {
        [$u0001, $u0006, $principal] = $this->usersOf('u0001', 'u0006', 'u0009');
        $flowId = $this->copyFlow([$u0001, $principal]);
        $this->addProxy($principal, $u0006, WorkflowProxyKind::Apply, $flowId);
        $source = $this->sourceCase($u0001);
        $this->grantFlow($u0006, self::COPY_FLOW, WorkflowFlowAction::Read);

        $this->assertSame(404, $this->copyStatusAs($principal, $source->id));
        $this->assertSame($principal->id, $this->copyPropsAs($u0006, $source->id)['applicant']);
    }

    /**
     * 本人は閲覧できず、代理元だけが参照・確認・管理の述語のそれぞれで閲覧できる → 404(申請の代理と承認の代理の両方を持っても)。
     */
    public function test_the_principal_alone_seeing_the_case_is_404(): void
    {
        [$u0001, $u0007, $principal] = $this->usersOf('u0001', 'u0007', 'u0009');
        $flowId = $this->copyFlow([$u0001, $principal]);
        $this->addProxy($principal, $u0007, WorkflowProxyKind::Apply, $flowId);
        $this->addProxy($principal, $u0007, WorkflowProxyKind::Approve, $flowId);
        $source = $this->sourceCase($u0001);
        $seenBy = fn () => [
            $this->copyStatusAs($principal, $source->id), $this->copyStatusAs($u0007, $source->id),
        ];

        $this->referTo($principal);
        $this->assertSame([200, 404], $seenBy(), '参照');
        $this->referTo(null);

        $confirm = $this->confirmTarget($source, $principal);
        $this->assertSame([200, 404], $seenBy(), '確認');
        $confirm->delete();

        $this->grantFlow($principal, self::COPY_FLOW, WorkflowFlowAction::Read);
        $this->assertSame([200, 404], $seenBy(), '管理');
    }

    /**
     * 確認対象者の行(承認のノードに。確認が開いた後の形をテストで作る)。
     */
    private function confirmTarget(WorkflowCase $case, User $user): WorkflowCaseConfirmTarget
    {
        return WorkflowCaseConfirmTarget::query()->create([
            'workflow_case_id' => $case->id,
            'workflow_case_node_id' => $this->caseNode($case, 'approval1')->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function statusesAs(User $user, array $ids): array
    {
        return array_map(fn (int $id) => $this->copyStatusAs($user, $id), $ids);
    }
}

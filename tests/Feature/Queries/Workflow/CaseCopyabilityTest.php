<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\AddRouteVersion;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzPolicy;
use App\Models\Master\User;
use App\Queries\Workflow\CaseCopyability;
use Carbon\CarbonImmutable;
use Tests\Feature\Http\Controllers\Web\Authz\EditsAuthz;
use Tests\Support\AppliesToTargets;
use Tests\Support\BuildsApplicationTargetCopies;
use Tests\Support\CopiesCases;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * ボタン「複製」の出し方(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.2 節の表、
 * 検査.md の 14 節の 12.1・12.2): 表の各行と認可の展開(申請のノードの対象者・create・今日の版・今日に有効でない代理・ルートの認可)を、
 * 移り先の GET が 403 にならないことと同じ組で比べる(申請の詳細の props は CaseShowCopyButtonTest)。
 */
final class CaseCopyabilityTest extends TransactionalTestCase
{
    use AppliesToTargets;
    use BuildsApplicationTargetCopies;
    use CopiesCases;
    use EditsAuthz;
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const ROUTE_GROUP = 'http-services.workflow.applications.create';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
    }

    /**
     * 自分ができる → 出す。自分はできず代理元だけができる → 出す(GET の既定の申請者はできる代理元。できない代理元が前にいても)。
     * どちらもできない(代理が無い・今日に有効でない代理・create が無い)→ 出さない。
     */
    public function test_the_applicants_rows(): void
    {
        [$u0001, $u0002, $u0003] = $this->usersOf('u0001', 'u0002', 'u0003');
        $flowId = $this->copyFlow([$u0001]);
        $source = $this->sourceCase($u0001)->id;

        $this->assertCopyable(true, $u0001, $flowId, $source);
        $this->assertCopyable(false, $u0002, $flowId, $source);

        $today = $this->operationDay()->toDateString();
        $this->addProxy($u0001, $u0002, WorkflowProxyKind::Apply, $flowId, '2026-01-01', $today);
        $this->assertCopyable(false, $u0002, $flowId, $source);

        $this->addProxy($u0003, $u0002, WorkflowProxyKind::Apply, $flowId);
        $this->addProxy($u0001, $u0002, WorkflowProxyKind::Apply, $flowId);
        $this->assertCopyable(true, $u0002, $flowId, $source);
        $this->assertSame($u0001->id, $this->copyPropsAs($u0002, $source)['applicant']);

        $this->allowOnly(self::COPY_FORM, RecordModuleAction::Create, $u0003);
        $this->assertCopyable(false, $u0001, $flowId, $source);
        $this->assertCopyable(false, $u0002, $flowId, $source);
    }

    /**
     * 今日の版: 元の申請の後に足した版の申請のノードから自分を外すと出ない、入れると出る。
     */
    public function test_the_version_of_today_decides(): void
    {
        [$u0001, $u0004] = $this->usersOf('u0001', 'u0004');
        $flowId = $this->copyFlow([$u0001]);
        $source = $this->sourceCase($u0001)->id;

        $this->addCopyVersion($u0004, '2026-10-01');
        $this->assertCopyable(false, $u0001, $flowId, $source);

        $this->addCopyVersion($u0001, $this->operationDay()->toDateString());
        $this->assertCopyable(true, $u0001, $flowId, $source);
    }

    /**
     * ルートの認可(ログインしている人で見る): 自分に移り先のルートを許さないと、自分も代理元もできる組でも出ない。代理元だけが許されていても出ない。
     */
    public function test_the_route_authorization_is_of_the_logged_in_user(): void
    {
        [$u0001, $u0002] = $this->usersOf('u0001', 'u0002');
        $flowId = $this->copyFlow([$u0001, $u0002]);
        $this->addProxy($u0001, $u0002, WorkflowProxyKind::Apply, $flowId);
        $source = $this->sourceCase($u0001)->id;
        AuthzPolicy::query()->where('authz_resource_group_id', $this->groupId(self::ROUTE_GROUP))->delete();

        $this->assertCopyable(false, $u0001, $flowId, $source);
        $this->assertCopyable(false, $u0002, $flowId, $source);

        $this->place(self::ROUTE_GROUP, $this->userColumn('u0001'), PolicyEffect::Permit);
        $this->assertCopyable(true, $u0001, $flowId, $source);
        $this->assertCopyable(false, $u0002, $flowId, $source);
    }

    /**
     * 判定と、移り先の GET が 403 にならないことが、同じ組で一致する(比べるテスト)。
     */
    private function assertCopyable(bool $expected, User $user, int $flowId, int $source): void
    {
        $canCopy = app(CaseCopyability::class)->canCopy($this->contextOf($user), $flowId);

        $this->assertSame([$expected, $expected], [$canCopy, $this->copyStatusAs($user, $source) !== 403]);
    }

    /**
     * 写しのフローに、申請のノードの対象者を渡した人にした版を足す(承認 = u0002)。
     */
    private function addCopyVersion(User $applicant, string $start): void
    {
        $node = fn (string $code, string $name, WorkflowNodeKind $kind, string $userCode) => new RouteNodeInput(
            $code, $name, $kind, WorkflowTargetKind::Fixed, [$this->userCondition($userCode)], ['ja' => $name],
        );
        $nodes = [
            $node('apply', '申請', WorkflowNodeKind::Apply, (string) $applicant->code),
            $node('approval1', '承認1', WorkflowNodeKind::Approve, 'u0002'),
        ];
        $edges = [new RouteEdgeInput('apply', 'approval1')];
        app(AddRouteVersion::class)->execute(self::COPY_FLOW, CarbonImmutable::parse($start), $nodes, $edges);
    }
}

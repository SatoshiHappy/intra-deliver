<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseRows;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細と一覧の行の can.reapply(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節の「再申請・取止め・引戻し」):
 * 本人として再申請できる || 申請の代理先として申請者を処理者にして表が許し、申請者が update を許される。詳細と一覧の行は同じ値で、
 * 真のとき再申請の画面の GET が同じ人に通り、偽の代理先には 403。代理元 A = u0009、申請の代理先 B = u0002、承認 = u0003(差戻す人)。
 */
final class CaseReapplyProxyTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    private int $flowId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow($this->operationDay()->toDateString().' 03:00:00 UTC');
        $this->flowId = $this->createFlow(approvals: [[$this->userCondition('u0003')]]);
    }

    /**
     * 本人の案件 / 代理元の案件の代理先: 真で、再申請の画面の GET が 200。
     */
    public function test_the_applicant_and_the_proxy_of_the_applicant_can_reapply(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        [$own, $principals] = [$this->sentBackOf($b), $this->sentBackOf($a)];

        $this->assertSame([true, true, 200], $this->reapplyOf($b, $own));
        $this->assertSame([true, true, 200], $this->reapplyOf($b, $principals));
    }

    /**
     * 代理元の案件で代理元に update が無い: 偽で、再申請の画面の GET が 403(代理先自身の update は見ない)。
     */
    public function test_the_proxy_cannot_reapply_when_the_principal_cannot_update(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId);
        $case = $this->sentBackOf($a);
        $this->allowOnly($this->projectFormModuleCode(), RecordModuleAction::Update, $b);

        $this->assertSame([false, false, 403], $this->reapplyOf($b, $case));
    }

    /**
     * 代理でない他人(案件を閲覧できる承認者)・承認の代理の代理先・期間の後の代理先は偽。
     */
    public function test_others_and_proxies_not_in_effect_cannot_reapply(): void
    {
        [$a, $b] = $this->people();
        $case = $this->sentBackOf($a);
        $approver = $this->seededUser('u0003');
        $this->addProxy($a, $b, WorkflowProxyKind::Approve, $this->flowId);
        $approveProxy = $this->cansOf($b, $case);
        $this->addProxy($a, $b, WorkflowProxyKind::Apply, $this->flowId, end: '2026-10-13');
        $ended = $this->cansOf($b, $case);

        $this->assertSame([false, false], $this->cansOf($approver, $case));
        $this->assertSame([[false, false], [false, false]], [$approveProxy, $ended]);
    }

    /**
     * 一覧の行の問い合わせは行の数に依らない: 同じ代理元の、別のフローの再申請待ちの行が 1 件でも 3 件でも同じ数(代理元ごとに 1 回の判定)。
     */
    public function test_the_queries_of_the_rows_do_not_grow_with_the_principals_rows(): void
    {
        [$a, $b] = $this->people();
        $this->addProxy($a, $b, WorkflowProxyKind::Apply);
        $flowCodes = [self::FLOW_CODE, 'wf_plan_2', 'wf_plan_3'];

        foreach (array_slice($flowCodes, 1) as $code) {
            $this->createFlow($code, approvals: [[$this->userCondition('u0003')]]);
        }

        $caseIds = array_map(fn (string $code) => $this->sentBackOf($a, $code)->id, $flowCodes);
        $requester = Requester::fromAccessContext($this->contextOf($b));
        $rowsOf = fn (array $ids) => app(CaseRows::class)->rows(
            $requester, $this->operationDay(), AccessContext::LOCALE_CODE, WorkflowCase::query()->whereKey($ids)->get(),
        );
        // 1 回の数え = 1 回の要求(要求の寿命で覚える読み込みを数えの間で持ち越さない)
        $countOf = function (array $ids) use ($rowsOf): int {
            $this->resetRequestScope();

            return count($this->recordedSql(fn () => $rowsOf($ids)));
        };
        $countOf($caseIds);

        $reapply = array_map(fn (array $row) => $row['can']['reapply'], $rowsOf($caseIds));

        $this->assertSame([true, true, true], $reapply);
        $this->assertSame($countOf([$caseIds[0]]), $countOf($caseIds));
    }

    /**
     * @return array{User, User} [代理元 A, 代理先 B]
     */
    private function people(): array
    {
        return [$this->seededUser('u0009'), $this->seededUser('u0002')];
    }

    private function sentBackOf(User $applicant, string $flowCode = self::FLOW_CODE): WorkflowCase
    {
        $case = $this->applyAs($applicant, $flowCode);
        $this->sendBack($this->seededUser('u0003'), $case, 'apply');

        return $case->refresh();
    }

    /**
     * @return array{bool, bool, int} [詳細の can.reapply, 一覧の行の can.reapply, 再申請の画面の GET の状態]
     */
    private function reapplyOf(User $user, WorkflowCase $case): array
    {
        return [...$this->cansOf($user, $case), $this->get("/workflow/cases/{$case->id}/reapply")->status()];
    }

    /**
     * @return array{bool, bool} [詳細の can.reapply, 一覧の行の can.reapply]
     */
    private function cansOf(User $user, WorkflowCase $case): array
    {
        $this->actingAs($user);
        $detail = $this->get("/workflow/cases/{$case->id}")->assertOk()->viewData('page')['props']['can']['reapply'];
        $requester = Requester::fromAccessContext($this->contextOf($user));
        $cases = WorkflowCase::query()->whereKey($case->id)->get();
        $row = app(CaseRows::class)->rows($requester, $this->operationDay(), AccessContext::LOCALE_CODE, $cases)[0];

        return [$detail, $row['can']['reapply']];
    }
}

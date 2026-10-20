<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 閲覧の述語の代理の項(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 1 節): 一時保存の述語 = 自分を申請の代理先とする、
 * そのフローに効く代理の代理元の一時保存。処理済の述語 = 自分を代理先とする代理(別を問わない。特定業務代理はそのフローだけ)の
 * 代理元が処理者の履歴の行がある案件。どちらも代理の期間の内だけで、期間の後・削除の後は述語から外れ、ほかの述語に合わなければ
 * 閲覧の判定が偽。操作の基準日 = 2026-10-13。
 */
final class CaseVisibilityProxyTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const TABS = ['draft', 'pending', 'processed', 'referenced'];

    public function test_the_draft_predicate_includes_drafts_of_the_principal_of_an_apply_proxy_for_that_flow(): void
    {
        [$flowId, $otherFlowId] = [$this->createFlow(), $this->createFlow('wf_other', 'ほかのフロー')];
        $principal = $this->seededUser('u0001');
        $draftId = $this->draftOf($principal);
        $this->addProxy($principal, $this->seededUser('u0005'), WorkflowProxyKind::Apply);
        $this->addProxy($principal, $this->seededUser('u0006'), WorkflowProxyKind::Apply, $flowId);
        $this->addProxy($principal, $this->seededUser('u0007'), WorkflowProxyKind::Apply, $otherFlowId);
        $this->addProxy($principal, $this->seededUser('u0008'), WorkflowProxyKind::Approve);

        $this->assertSame(
            ['u0001' => ['draft'], 'u0005' => ['draft'], 'u0006' => ['draft'], 'u0007' => [], 'u0008' => []],
            $this->tabsOf($draftId, 'u0001', 'u0005', 'u0006', 'u0007', 'u0008'),
        );
    }

    /**
     * 代理元 u0002 が承認した案件。別を問わず(申請の代理でも)代理先の処理済に出る。特定業務代理は別のフローなら出ない。
     */
    public function test_the_processed_predicate_includes_cases_processed_by_the_principal_of_any_kind(): void
    {
        [$flowId, $otherFlowId] = [$this->createFlow(), $this->createFlow('wf_other', 'ほかのフロー')];
        $case = $this->applyAs($this->seededUser('u0001'));
        $principal = $this->seededUser('u0002');
        $this->operate(ApproveCase::class, $principal, $case);
        $this->addProxy($principal, $this->seededUser('u0005'), WorkflowProxyKind::Apply);
        $this->addProxy($principal, $this->seededUser('u0006'), WorkflowProxyKind::Approve, $flowId);
        $this->addProxy($principal, $this->seededUser('u0007'), WorkflowProxyKind::Approve, $otherFlowId);

        $this->assertSame(
            ['u0002' => ['processed'], 'u0005' => ['processed'], 'u0006' => ['processed'], 'u0007' => []],
            $this->tabsOf($case->id, 'u0002', 'u0005', 'u0006', 'u0007'),
        );
    }

    /**
     * 期間の後(行の end_date の当日)と削除の後は、一時保存・未処理・処理済から消え、閲覧の判定が偽になる。
     */
    public function test_after_the_period_or_deletion_the_case_is_not_visible_through_the_proxy(): void
    {
        $this->createFlow();
        [$applicant, $approver, $proxy] = $this->seededUsers('u0001', 'u0002', 'u0005');
        $draftId = $this->draftOf($applicant);
        $pending = $this->applyAs($applicant);
        $processed = $this->applyAs($applicant);
        $this->operate(ApproveCase::class, $approver, $processed);
        $applyProxy = $this->addProxy($applicant, $proxy, WorkflowProxyKind::Apply, null, '2026-10-01', '2026-10-14');
        $approveProxy = $this->addProxy($approver, $proxy, start: '2026-10-01', end: '2026-10-14');
        $caseIds = [$draftId, $pending->id, $processed->id];

        $within = array_map(fn (int $caseId) => $this->tabsOf($caseId, 'u0005'), $caseIds);
        $after = array_map(fn (int $caseId) => $this->tabsAt($caseId, '2026-10-14', 'u0005'), $caseIds);
        $visibleAfter = $this->visibleOn($proxy, '2026-10-14', $caseIds);
        $applyProxy->delete();
        $approveProxy->delete();

        // 申請者 u0001 が申請の行の処理者なので、申請の代理先の u0005 には申請した 2 件が処理済にも出る(別を問わない)
        $this->assertSame(
            [['u0005' => ['draft']], ['u0005' => ['pending', 'processed']], ['u0005' => ['processed']]],
            $within,
        );
        $this->assertSame([['u0005' => []], ['u0005' => []], ['u0005' => []]], $after);
        $this->assertSame([false, false, false], $visibleAfter);
        $this->assertSame([false, false, false], $this->visibleOn($proxy, '2026-10-13', $caseIds));
    }

    private function draftOf(User $owner): int
    {
        $input = new DraftInput('下書き', null, [], []);

        return app(CreateDraft::class)->execute($this->contextOf($owner), self::FLOW_CODE, $input);
    }

    /**
     * 人ごとに、その案件が出る 4 タブの述語(基準日 = 操作の基準日)。
     *
     * @return array<string, list<string>>
     */
    private function tabsOf(int $caseId, string ...$codes): array
    {
        return $this->tabsAt($caseId, null, ...$codes);
    }

    /**
     * @return array<string, list<string>>
     */
    private function tabsAt(int $caseId, ?string $date, string ...$codes): array
    {
        $day = $this->contextOf(null, date: $date)->date;
        $result = [];

        foreach ($codes as $code) {
            $requester = new Requester($this->seededUser($code)->id, null);
            $result[$code] = array_values(array_filter(self::TABS, fn (string $tab) => app(CaseVisibility::class)
                ->{$tab}(WorkflowCase::query()->whereKey($caseId), $requester, $day)->exists()));
        }

        return $result;
    }

    /**
     * @param  list<int>  $caseIds
     * @return list<bool>
     */
    private function visibleOn(User $user, string $date, array $caseIds): array
    {
        $day = $this->contextOf(null, date: $date)->date;
        $requester = new Requester($user->id, null);

        return array_map(fn (int $caseId) => $this->visibility()->isVisible($requester, $day, $caseId), $caseIds);
    }

    private function visibility(): CaseVisibility
    {
        return app(CaseVisibility::class);
    }
}

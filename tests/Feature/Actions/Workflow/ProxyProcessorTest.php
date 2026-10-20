<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 処理者の確かめと役割「ノードの処理対象者」(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節、
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.2 節の「処理者の決め方の境界」): principal_user_id が自分を代理先とする代理元でない・
 * 別やフローが合わない・期間の外 → 422 principal_user_id。代理の項だけで当たる人が自分として送る → 422 principal_user_id。どの項にも当たらない
 * → 422 case。A を代理先とする D のノードを B が A として → 422 principal_user_id。本人の行と代理の項の両方で当たる人は自分としても代理元としても
 * 処理でき、履歴の処理者がそれぞれ自分・代理元。失敗では何も変わらない。フロー = 申請 → 承認 1(u0002)→ 承認 2(u0003)、参照者 = 経理部(u0010)。
 */
final class ProxyProcessorTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use CatchesValidationErrors;
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const PRINCIPAL_ERROR = ['principal_user_id' => '選択した処理担当者として処理できません。代理の設定を確認してください。'];

    private const NOT_PERMITTED = ['case' => 'この申請は今この操作をできません'];

    /**
     * 参照者 u0010(案件を閲覧できる)が u0002 として承認する。代理が無い・別が申請・別のフローの特定業務代理・期間の後は、どれも 422 principal_user_id。
     */
    public function test_a_principal_without_an_effective_proxy_of_the_node_kind_and_flow_is_rejected(): void
    {
        $this->createFlow();
        $otherFlowId = $this->createFlow('wf_other', 'ほかのフロー');
        [$principal, $referrer] = $this->seededUsers('u0002', 'u0010');
        $case = $this->applyAs($this->seededUser('u0001'));
        $proxies = [
            'none' => fn () => null,
            'apply kind' => fn () => $this->addProxy($principal, $referrer, WorkflowProxyKind::Apply),
            'other flow' => fn () => $this->addProxy($principal, $referrer, flowId: $otherFlowId),
            'after the period' => fn () => $this->addProxy($principal, $referrer, end: '2026-10-13'),
        ];

        foreach ($proxies as $label => $add) {
            $add();
            $this->assertRejected(self::PRINCIPAL_ERROR, $case, $referrer, $principal->id, $label);
        }
    }

    public function test_a_user_who_matches_only_by_the_proxy_term_cannot_process_as_itself(): void
    {
        $this->createFlow();
        [$principal, $proxy] = $this->seededUsers('u0002', 'u0005');
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->addProxy($principal, $proxy);

        $this->assertRejected(self::PRINCIPAL_ERROR, $case, $proxy, null);
        $this->assertRejected(self::NOT_PERMITTED, $case, $this->seededUser('u0010'), null);
    }

    /**
     * D = u0002 のノード(A = u0005 の本人の行は無い)を、A の代理先 B = u0010(参照者)が A として承認すると 422 principal_user_id(項は自分から 1 段だけ)。
     */
    public function test_a_proxy_of_a_proxy_cannot_process_as_the_middle_principal(): void
    {
        $this->createFlow();
        [$d, $a, $b] = $this->seededUsers('u0002', 'u0005', 'u0010');
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->addProxy($d, $a);
        $this->addProxy($a, $b);

        $this->assertRejected(self::PRINCIPAL_ERROR, $case, $b, $a->id);
    }

    /**
     * 承認 1 の処理対象者が u0002 と u0005 で、u0005 は u0002 の代理先。u0005 は自分としても u0002 としても承認できる。
     */
    public function test_a_user_matching_by_both_terms_can_process_as_itself_or_as_the_principal(): void
    {
        $this->createFlow(approvals: [
            [$this->userCondition('u0002'), $this->userCondition('u0005')],
            [$this->userCondition('u0003')],
        ]);
        [$principal, $both] = $this->seededUsers('u0002', 'u0005');
        $asItself = $this->applyAs($this->seededUser('u0001'));
        $asPrincipal = $this->applyAs($this->seededUser('u0001'));
        $this->addProxy($principal, $both);

        $this->approveAs($both, $asItself, null);
        $this->approveAs($both, $asPrincipal, $principal->id);

        $this->assertSame([$both->id, null], $this->lastApproval($asItself));
        $this->assertSame([$principal->id, $both->id], $this->lastApproval($asPrincipal));
    }

    /**
     * @param  array<string, string>  $expected
     */
    private function assertRejected(
        array $expected,
        WorkflowCase $case,
        User $user,
        ?int $principalUserId,
        string $label = '',
    ): void {
        $snapshot = fn () => [$this->nodeStates($case), $this->workflowCounts(), $this->revisionOf($case)];
        $before = $snapshot();

        $errors = $this->validationErrors(fn () => $this->approveAs($user, $case, $principalUserId));

        $this->assertSame($expected, $errors, $label);
        $this->assertSame($before, $snapshot(), $label);
    }

    private function approveAs(User $user, WorkflowCase $case, ?int $principalUserId): void
    {
        $nodeId = $this->caseNode($case, 'approval1')->id;
        $context = $this->contextOf($user);

        $revision = $this->revisionOf($case);

        app(ApproveCase::class)->execute($context, $case->id, $revision, $nodeId, null, [], $principalUserId);
    }

    /**
     * 最新の承認の行の [処理者, 実行者]。
     *
     * @return array{int, int|null}
     */
    private function lastApproval(WorkflowCase $case): array
    {
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail();

        return [$history->user_id, $history->executor_user_id];
    }
}

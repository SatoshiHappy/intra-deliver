<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 代理先としての操作(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.6 節):
 * 承認・承認終了・否認・差戻し・引戻し・再申請・取止めが代理元として成功し、履歴の処理者 = 代理元・実行者 = 代理先(本人の操作の実行者は NULL)。
 * 引戻しは代理元本人が処理した行を引戻せ、X の処理対象者は代理元。代理の別は引戻す行のノードの種類で決まる。
 * 代理で処理した案件は代理の期間の内だけ代理先に見える。フロー = 申請 → 承認 1(u0002)→ 承認 2(u0003)。
 */
final class ProxyOperationsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use CatchesValidationErrors;
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const PRINCIPAL_ERROR = ['principal_user_id' => '選択した処理担当者として処理できません。代理の設定を確認してください。'];

    public function test_each_approval_operation_as_the_principal_records_the_principal_and_the_executor(): void
    {
        $this->createFlow();
        [$applicant, $approver, $proxy] = $this->seededUsers('u0001', 'u0002', 'u0005');
        $this->addProxy($approver, $proxy);
        $operations = [
            'approve' => fn (WorkflowCase $c) => $this->onApproval1(ApproveCase::class, $proxy, $c, $approver),
            'approve_early' => fn (WorkflowCase $c) => $this->onApproval1(
                ApproveCaseEarly::class, $proxy, $c, $approver,
            ),
            'reject' => fn (WorkflowCase $c) => $this->onApproval1(RejectCase::class, $proxy, $c, $approver),
            'send_back' => fn (WorkflowCase $c) => $this->sendBackAs($proxy, $c, $approver),
        ];
        $actual = [];

        foreach ($operations as $action => $operate) {
            $case = $this->applyAs($applicant);
            $operate($case);
            $actual[$action] = $this->lastHistory($case);
        }

        $this->assertSame([
            'approve' => ['approve', 'u0002', 'u0005'],
            'approve_early' => ['approve_early', 'u0002', 'u0005'],
            'reject' => ['reject', 'u0002', 'u0005'],
            'send_back' => ['send_back', 'u0002', 'u0005'],
        ], $actual);
    }

    public function test_reapply_and_withdraw_as_the_applicant_and_an_own_operation_has_no_executor(): void
    {
        $this->createFlow();
        [$applicant, $approver, $proxy] = $this->seededUsers('u0001', 'u0002', 'u0006');
        $this->addProxy($applicant, $proxy, WorkflowProxyKind::Apply);
        $reapplied = $this->applyAs($applicant);
        $withdrawn = $this->applyAs($applicant);

        foreach ([$reapplied, $withdrawn] as $case) {
            $this->sendBack($approver, $case, 'apply');
        }

        $context = $this->contextOf($proxy);
        $input = $this->projectInput();
        $revision = $this->revisionOf($reapplied);
        app(ReapplyCase::class)->execute($context, $reapplied->id, $revision, $input, $applicant->id);
        $revision = $this->revisionOf($withdrawn);
        app(WithdrawCase::class)->execute($context, $withdrawn->id, $revision, null, $applicant->id);

        $this->assertSame([
            ['reapply', 'u0001', 'u0006'],
            ['withdraw', 'u0001', 'u0006'],
            ['send_back', 'u0002', null],
        ], [
            $this->lastHistory($reapplied),
            $this->lastHistory($withdrawn),
            $this->historyOf($withdrawn, WorkflowAction::SendBack),
        ]);
        $this->assertSame(
            [['apply', 'done', 'reapply', []], ['approval1', 'waiting', null, [$approver->id]]],
            array_slice($this->nodeStates($reapplied), 0, 2),
        );
    }

    /**
     * 代理元 u0002 本人が承認した承認 1 を、代理先 u0005 が u0002 として引戻す: 承認 1 の処理対象者 = 代理元。申請の行(申請のノード)は申請の代理で引戻す。
     */
    public function test_the_proxy_pulls_back_a_row_the_principal_processed_and_it_returns_to_the_principal(): void
    {
        $this->createFlow();
        [$applicant, $approver, $approveProxy, $applyProxy] = $this->seededUsers('u0001', 'u0002', 'u0005', 'u0006');
        $this->addProxy($approver, $approveProxy);
        $this->addProxy($applicant, $applyProxy, WorkflowProxyKind::Apply);
        $approved = $this->applyAs($applicant);
        $this->operate(ApproveCase::class, $approver, $approved);
        $applied = $this->applyAs($applicant);

        $this->pullBackAs($approveProxy, $approved, 'approval1', $approver);
        $this->pullBackAs($applyProxy, $applied, 'apply', $applicant);

        $this->assertSame(['pull_back', 'u0002', 'u0005'], $this->lastHistory($approved));
        $this->assertSame(['approval1', 'waiting', null, [$approver->id]], $this->nodeStates($approved)[1]);
        $this->assertSame(['pull_back', 'u0001', 'u0006'], $this->lastHistory($applied));
        $this->assertSame(['apply', 'reapply', null, [$applicant->id]], $this->nodeStates($applied)[0]);
    }

    /**
     * 申請の行は申請の代理、承認の行は承認の代理でだけ引戻せる(別が合わなければ 422 principal_user_id で何も変わらない)。
     */
    public function test_the_kind_for_a_pull_back_is_the_kind_of_the_pulled_node(): void
    {
        $this->createFlow();
        [$applicant, $approver, $approveProxy, $applyProxy] = $this->seededUsers('u0001', 'u0002', 'u0005', 'u0006');
        $this->addProxy($applicant, $approveProxy);
        $this->addProxy($approver, $applyProxy, WorkflowProxyKind::Apply);
        $applied = $this->applyAs($applicant);
        $approved = $this->applyAs($applicant);
        $this->operate(ApproveCase::class, $approver, $approved);
        $snapshot = fn () => [$this->nodeStates($applied), $this->nodeStates($approved), $this->workflowCounts()];
        $before = $snapshot();

        $errors = [
            $this->validationErrors(fn () => $this->pullBackAs($approveProxy, $applied, 'apply', $applicant)),
            $this->validationErrors(fn () => $this->pullBackAs($applyProxy, $approved, 'approval1', $approver)),
        ];

        $this->assertSame([self::PRINCIPAL_ERROR, self::PRINCIPAL_ERROR], $errors);
        $this->assertSame($before, $snapshot());
    }

    /**
     * 代理で処理した案件は代理の期間の内だけ代理先の処理済に出る(履歴の処理者 = 代理元。期間の後は閲覧できない)。
     */
    public function test_a_case_processed_as_the_principal_is_visible_to_the_proxy_only_within_the_period(): void
    {
        $this->createFlow();
        [$applicant, $approver, $proxy] = $this->seededUsers('u0001', 'u0002', 'u0005');
        $this->addProxy($approver, $proxy, start: '2026-10-01', end: '2026-10-14');
        $case = $this->applyAs($applicant);
        $this->onApproval1(ApproveCase::class, $proxy, $case, $approver);
        $visibility = app(CaseVisibility::class);
        $requester = new Requester($proxy->id, null);
        $query = fn () => WorkflowCase::query()->whereKey($case->id);
        $processedOn = fn (string $date) => $visibility
            ->processed($query(), $requester, CarbonImmutable::parse($date))->exists();

        $this->assertSame([true, false], [$processedOn('2026-10-13'), $processedOn('2026-10-14')]);
        $this->assertFalse($visibility->isVisible($requester, CarbonImmutable::parse('2026-10-14'), $case->id));
    }

    /**
     * @param  class-string<ApproveCase|ApproveCaseEarly|RejectCase>  $operation
     */
    private function onApproval1(string $operation, User $user, WorkflowCase $case, User $principal): void
    {
        $nodeId = $this->caseNode($case, 'approval1')->id;

        $context = $this->contextOf($user);
        $revision = $this->revisionOf($case);
        app($operation)->execute($context, $case->id, $revision, $nodeId, null, principalUserId: $principal->id);
    }

    private function sendBackAs(User $user, WorkflowCase $case, User $principal): void
    {
        [$nodeId, $toId] = [$this->caseNode($case, 'approval1')->id, $this->caseNode($case, 'apply')->id];

        $context = $this->contextOf($user);
        $revision = $this->revisionOf($case);
        app(SendBackCase::class)->execute($context, $case->id, $revision, $nodeId, $toId, null, $principal->id);
    }

    private function pullBackAs(User $user, WorkflowCase $case, string $code, User $principal): void
    {
        $nodeId = $this->caseNode($case, $code)->id;

        $context = $this->contextOf($user);
        app(PullBackCase::class)->execute($context, $case->id, $this->revisionOf($case), $nodeId, null, $principal->id);
    }

    /**
     * 最新の履歴の行の [種別, 処理者のコード, 実行者のコード]。
     *
     * @return array{string, string, string|null}
     */
    private function lastHistory(WorkflowCase $case): array
    {
        $rows = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id);

        return $this->rowOf($rows->latest('id')->firstOrFail());
    }

    /**
     * @return array{string, string, string|null}
     */
    private function historyOf(WorkflowCase $case, WorkflowAction $action): array
    {
        $rows = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->where('action', $action);

        return $this->rowOf($rows->latest('id')->firstOrFail());
    }

    /**
     * @return array{string, string, string|null}
     */
    private function rowOf(WorkflowCaseHistory $history): array
    {
        $codes = User::query()->pluck('code', 'id');

        $executor = $history->executor_user_id;

        $executorCode = $executor === null ? null : (string) $codes[$executor];

        return [$history->action->value, (string) $codes[$history->user_id], $executorCode];
    }
}

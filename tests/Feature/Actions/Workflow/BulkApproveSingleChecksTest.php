<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\CaseProxyChecks;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use App\Queries\Workflow\CaseSituation;
use App\Support\AccessContext;
use DateTimeInterface;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsBulkOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 一括承認の 1 件が、組の判定の後に単独の承認と同じ処理担当者の確かめと表の判定を通ること(docs の
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。組の判定が許しても単独の判定が拒めば止まり、何も書かない。
 * 本物の値では組の判定と単独の判定が同じ結果になるので、単独の側だけを拒む偽物(本物を包むサブクラス)で確かめる。
 * 結果の行の分け方は偽物の影響を受けうるので見ない。フロー = 申請(u0001)→ 承認 1(u0002 と u0003)→ 承認 2(u0004)。
 */
final class BulkApproveSingleChecksTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsBulkOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->createFlow(approvals: [
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            [$this->userCondition('u0004')],
        ]);
    }

    public function test_the_table_of_the_single_approval_refusing_stops_without_writing(): void
    {
        $approver = $this->seededUser('u0002');
        $cases = [$this->applied(), $this->applied()];
        $rows = array_map(fn (WorkflowCase $case) => $this->screenRowOf($case, $approver), $cases);
        $before = $this->writtenOf($cases);
        $this->refuseApprovalInTheTable();

        $result = $this->bulkApprove($approver, $rows);

        $this->assertSame([2, 0], [$result->total(), $result->succeeded()]);
        $this->assertSame($before, $this->writtenOf($cases));
    }

    public function test_the_principal_check_of_the_single_approval_refusing_stops_without_writing(): void
    {
        $proxy = $this->seededUser('u0010');
        $this->addProxy($this->seededUser('u0002'), $proxy);
        $cases = [$this->applied(), $this->applied()];
        $rows = array_map(fn (WorkflowCase $case) => $this->screenRowOf($case, $proxy), $cases);
        $this->assertNotNull($rows[0]['principal_user_id']);
        $before = $this->writtenOf($cases);
        $this->refuseThePrincipal();

        $result = $this->bulkApprove($proxy, $rows);

        $this->assertSame([2, 0], [$result->total(), $result->succeeded()]);
        $this->assertSame($before, $this->writtenOf($cases));
    }

    private function applied(): WorkflowCase
    {
        return $this->applyAs($this->seededUser('u0001'));
    }

    /**
     * 案件ごとの [改訂番号, 履歴の数]。
     *
     * @param  list<WorkflowCase>  $cases
     * @return list<array{int, int}>
     */
    private function writtenOf(array $cases): array
    {
        return array_map(fn (WorkflowCase $case) => [
            $this->revisionOf($case),
            WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->count(),
        ], $cases);
    }

    /**
     * 表の判定(permitsIn)だけが承認を拒む。組の判定(bulkApprovalsOf)と、一括承認の道で呼ばれるほかのメソッドは本物へ渡す
     * (道の外のメソッドは包まない)。
     */
    private function refuseApprovalInTheTable(): void
    {
        $real = $this->app->build(CaseOperationDecider::class);
        $this->app->instance(CaseOperationDecider::class, new class($real) extends CaseOperationDecider
        {
            public function __construct(private CaseOperationDecider $real) {}

            public function permitsIn(
                WorkflowOperation $operation,
                Requester $requester,
                DateTimeInterface $date,
                CaseSituation $situation,
                ?int $nodeId,
                bool $withoutOwnRow = false,
                bool $canOperate = false,
            ): bool {
                return $operation !== WorkflowOperation::Approve && $this->real->permitsIn(
                    $operation, $requester, $date, $situation, $nodeId, $withoutOwnRow, $canOperate,
                );
            }

            public function bulkApprovalsOf(Requester $requester, DateTimeInterface $date, array $situations): array
            {
                return $this->real->bulkApprovalsOf($requester, $date, $situations);
            }

            public function canConfirm(Requester $requester, DateTimeInterface $date, CaseSituation $situation): bool
            {
                return $this->real->canConfirm($requester, $date, $situation);
            }

            public function situationsOf(
                Requester $requester,
                DateTimeInterface $date,
                array $cases,
                ?int $processorUserId = null,
            ): array {
                return $this->real->situationsOf($requester, $date, $cases, $processorUserId);
            }
        });
    }

    /**
     * 承認の処理担当者の確かめ(processorOf)だけが代理元を拒む。ほかの操作は本物へ渡す(一括承認の道の外のメソッドは包まない)。
     */
    private function refuseThePrincipal(): void
    {
        $real = $this->app->build(CaseProxyChecks::class);
        $this->app->instance(CaseProxyChecks::class, new class($real) extends CaseProxyChecks
        {
            public function __construct(private CaseProxyChecks $real) {}

            public function processorOf(
                WorkflowOperation $operation,
                AccessContext $context,
                WorkflowCase $case,
                ?WorkflowNodeKind $kind,
                ?int $principalUserId,
            ): int {
                if ($operation === WorkflowOperation::Approve) {
                    throw CaseOperationErrors::principal();
                }

                return $this->real->processorOf($operation, $context, $case, $kind, $principalUserId);
            }
        });
    }
}

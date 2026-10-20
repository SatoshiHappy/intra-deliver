<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\TransferCase;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\ChecksCaseOperationTable;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節、同時実行と検査.md の 14.1 節)のうち、保留と振替の 4 つの状況の全組。
 * 期待と記号は {@see ChecksCaseOperationTable} に足して: D = 承認 2(u0007)、E・F = B の承認の代理先(u0008・u0009)、H = 申請者 A の承認の代理先(u0011)。
 * 保留の状況はフロー = 申請 → 承認 1(B・C)→ 承認 2(D)、振替の状況は承認 1 = B だけ(B が C へ振替)。代理元として操作する役割(E・F が B として)は、
 * 申請の系統の操作(再申請・取止め)で承認の代理が合わないので 422 principal_user_id(13 節)。入力 = 承認 1・差戻し先 = 申請・振替先 = U。
 *
 * @phpstan-import-type Result from ChecksCaseOperationTable
 * @phpstan-import-type Inputs from ChecksCaseOperationTable
 */
final class CaseOperationTableHoldTest extends TransactionalTestCase
{
    use ChecksCaseOperationTable;
    use RegistersProxies;

    /** 既にある案件の操作の数(一時保存(新規)を除く 13) */
    private const OPERATIONS = 13;

    /** 保留者側の 3 つ(本人・代理先が本人として・ほかの代理先が本人として) */
    private const HOLD_SIDE = ['holder', 'holderProxy', 'otherProxy'];

    /** 申請の系統の操作を代理元(承認の代理)として送ると 422 principal_user_id */
    private const APPLY_KIND = ['reapply' => '422:principal_user_id', 'withdraw' => '422:principal_user_id'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * @return array<string, string|null>
     */
    protected function tablePeople(): array
    {
        return [...self::PEOPLE, 'D' => 'u0007', 'E' => 'u0008', 'F' => 'u0009', 'H' => 'u0011'];
    }

    /**
     * B が本人として保留(保留者側 = B と、B の代理先 E・F(B として))。
     */
    public function test_held_by_the_target_himself(): void
    {
        $case = $this->heldCase();
        $this->operateOnApproval1(HoldCase::class, 'B', $case, null);

        $this->assertSame(13 * self::OPERATIONS, $this->assertHeldTable($case));
    }

    /**
     * B の代理先 E が B として保留(保留者側 = B 本人と、E・F(B として)。E 自身としては外れる)。
     */
    public function test_held_by_a_proxy_as_the_principal(): void
    {
        $case = $this->heldCase();
        $this->operateOnApproval1(HoldCase::class, 'E', $case, 'B');

        $this->assertSame(13 * self::OPERATIONS, $this->assertHeldTable($case));
    }

    /**
     * B が C へ振替した後(承認 1 が C の承認待ち。B は処理済に出るが操作できない)。
     */
    public function test_after_a_transfer(): void
    {
        $case = $this->transferredCase(false);

        $this->assertSame(12 * self::OPERATIONS, $this->assertTransferredTable($case));
    }

    /**
     * B が保留してから C へ振替した後(保留が解けて承認 1 が C の承認待ち)。
     */
    public function test_after_a_transfer_while_held(): void
    {
        $case = $this->transferredCase(true);

        $this->assertSame(12 * self::OPERATIONS, $this->assertTransferredTable($case));
    }

    private function assertHeldTable(WorkflowCase $case): int
    {
        $processed = 'ABEFH';
        $early = ['done/apply', 'done/approve_early', 'unreached'];
        $success = [
            'approve' => $this->active(['done/apply', 'done/approve', 'waiting:D'], 'D', $processed),
            'approve_early' => $this->completed($early, 'approved_early', $processed),
            'reject' => $this->completed(['done/apply', 'done/reject', 'unreached'], 'rejected', $processed),
            'send_back' => $this->active(['reapply:A', 'unreached', 'unreached'], 'A', $processed),
            'transfer' => $this->active(['done/apply', 'waiting:CU', 'unreached'], 'CU', $processed),
            'release' => $this->active(['done/apply', 'waiting:BC', 'unreached'], 'BCEF', $processed),
        ];

        return $this->assertOperationTable($case, [
            'roles' => [
                'holder' => 'B', 'holderProxy' => 'E', 'otherProxy' => 'F', 'proxySelf' => 'E',
                'heldTarget' => 'C', 'applicantProxy' => 'H', 'nextTarget' => 'D',
            ],
            'principals' => ['holderProxy' => 'B', 'otherProxy' => 'B'],
            'rejections' => ['holderProxy' => self::APPLY_KIND, 'otherProxy' => self::APPLY_KIND],
            'visible' => 'ABCEFHR',
            'success' => array_map(fn (array $result) => [self::HOLD_SIDE, $result], $success),
            'inputs' => $this->approval1Inputs($case),
        ]);
    }

    private function assertTransferredTable(WorkflowCase $case): int
    {
        $processed = 'ABCEFH';
        $early = ['done/apply', 'done/approve_early', 'unreached'];
        $success = [
            'approve' => $this->active(['done/apply', 'done/approve', 'waiting:D'], 'D', $processed),
            'approve_early' => $this->completed($early, 'approved_early', $processed),
            'reject' => $this->completed(['done/apply', 'done/reject', 'unreached'], 'rejected', $processed),
            'send_back' => $this->active(['reapply:A', 'unreached', 'unreached'], 'A', $processed),
            'hold' => $this->active(['done/apply', 'hold:C', 'unreached'], 'C', $processed),
            'transfer' => $this->active(['done/apply', 'waiting:U', 'unreached'], 'U', $processed),
        ];

        return $this->assertOperationTable($case, [
            'roles' => [
                'currentTarget' => 'C', 'transferor' => 'B', 'transferorProxy' => 'E', 'otherProxy' => 'F',
                'applicantProxy' => 'H', 'nextTarget' => 'D',
            ],
            'visible' => 'ABCEFHR',
            'success' => array_map(fn (array $result) => ['currentTarget', $result], $success),
            'inputs' => $this->approval1Inputs($case),
        ]);
    }

    /**
     * 承認 1 = B・C の案件(B の代理先 E・F、A の代理先 H)。
     */
    private function heldCase(): WorkflowCase
    {
        $approval1 = [$this->userCondition('u0002'), $this->userCondition('u0003')];
        $approvals = [$approval1, [$this->userCondition('u0007')]];
        $this->createFlow(approvals: $approvals, applicants: $this->tableApplicants());
        $this->addProxies();

        return $this->applyAs($this->seededUser('u0001'));
    }

    /**
     * 承認 1 = B だけの案件で、B が(保留してから)C へ振替した後。
     */
    private function transferredCase(bool $holdsFirst): WorkflowCase
    {
        $approvals = [[$this->userCondition('u0002')], [$this->userCondition('u0007')]];
        $this->createFlow(approvals: $approvals, applicants: $this->tableApplicants());
        $this->addProxies();
        $case = $this->applyAs($this->seededUser('u0001'));

        if ($holdsFirst) {
            $this->operateOnApproval1(HoldCase::class, 'B', $case, null);
        }

        $this->operateOnApproval1(TransferCase::class, 'B', $case, null);

        return $case;
    }

    /**
     * @return list<SelectionCondition>
     */
    private function tableApplicants(): array
    {
        return [$this->userCondition('u0001'), $this->userCondition('u0004')];
    }

    private function addProxies(): void
    {
        [$b, $a] = [$this->seededUser('u0002'), $this->seededUser('u0001')];
        $this->addProxy($b, $this->seededUser('u0008'));
        $this->addProxy($b, $this->seededUser('u0009'));
        $this->addProxy($a, $this->seededUser('u0011'));
    }

    /**
     * 承認 1 の保留(コメントなし)か C への振替を、$letter の人が $principal(null = 自分)として行う。
     *
     * @param  class-string<HoldCase|TransferCase>  $operation
     */
    private function operateOnApproval1(string $operation, string $letter, WorkflowCase $case, ?string $principal): void
    {
        $context = $this->contextOf($this->person($letter));
        $nodeId = $this->caseNode($case, 'approval1')->id;
        $principalId = $principal === null ? null : $this->person($principal)?->id;
        $revision = $this->revisionOf($case);

        if ($operation === TransferCase::class) {
            $toUserId = $this->person('C')?->id;
            app(TransferCase::class)->execute($context, $case->id, $revision, $nodeId, $toUserId, null, $principalId);

            return;
        }

        app(HoldCase::class)->execute($context, $case->id, $revision, $nodeId, null, $principalId);
    }

    /**
     * @return Inputs
     */
    private function approval1Inputs(WorkflowCase $case): array
    {
        $approval1 = $this->caseNode($case, 'approval1')->id;

        $apply = $this->caseNode($case, 'apply')->id;

        return ['node' => $approval1, 'sendBackTo' => $apply, 'pullBack' => $approval1, 'assignments' => []];
    }

    /**
     * @param  list<string>  $nodes
     * @return Result
     */
    private function active(array $nodes, string $pending, string $processed): array
    {
        $tabs = ['draft' => '', 'pending' => $pending, 'processed' => $processed, 'referenced' => 'R'];

        return ['nodes' => $nodes, 'state' => 'active', 'histories' => 1, 'tabs' => $tabs];
    }

    /**
     * @param  list<string>  $nodes
     * @return Result
     */
    private function completed(array $nodes, string $reason, string $processed): array
    {
        $tabs = ['draft' => '', 'pending' => '', 'processed' => $processed, 'referenced' => 'R'];

        return ['nodes' => $nodes, 'state' => "completed:{$reason}", 'histories' => 1, 'tabs' => $tabs];
    }
}

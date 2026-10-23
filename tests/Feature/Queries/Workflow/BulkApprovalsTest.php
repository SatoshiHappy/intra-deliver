<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\BulkApproval;
use App\Queries\Workflow\CaseOperationDecider;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\OperatesHolds;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsBulkOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 一括承認で選べる案件と組(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節、検査 = 同時実行と検査.md の 14.2 節)と、
 * 一括確認で選べる案件。行の組(画面の can.bulkApprove)と処理の時の組が一致することを、どの場面でも確かめる。
 * 申請者承認防止の代理の項は今日を時計から読むので、時計を操作の基準日に合わせる。
 */
final class BulkApprovalsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use OperatesHolds;
    use RegistersProxies;
    use RunsBulkOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * ちょうど 1 つの組で選べる: 処理対象者は自分として、代理先は代理元として。処理対象者でない閲覧者(参照者)は選べない。
     */
    public function test_exactly_one_pair_is_selectable(): void
    {
        $this->createFlow();
        [$approver, $proxy, $referrer] = $this->seededUsers('u0002', 'u0005', 'u0010');
        $this->addProxy($approver, $proxy);
        $case = $this->applyAs($this->seededUser('u0001'));
        $nodeId = $this->caseNode($case, 'approval1')->id;

        $this->assertPair(new BulkApproval($nodeId, '承認1', null), $case, $approver);
        $this->assertPair(new BulkApproval($nodeId, '承認1', $approver->id), $case, $proxy);
        $this->assertPair(null, $case, $referrer);
    }

    /**
     * 同じノードに自分と代理元の両方が当たれば自分として 1 つ。同じノードに代理元が 2 人なら組が 2 つで選べない。
     */
    public function test_one_node_with_self_and_a_principal_and_one_node_with_two_principals(): void
    {
        $this->createTwoTargetFlow();
        [$u0002, $u0003, $proxy] = $this->seededUsers('u0002', 'u0003', 'u0005');
        $this->addProxy($u0003, $u0002);
        $this->addProxy($u0002, $proxy);
        $this->addProxy($u0003, $proxy);
        $case = $this->applyAs($this->seededUser('u0001'));

        $this->assertPair(new BulkApproval($this->caseNode($case, 'approval1')->id, '承認1', null), $case, $u0002);
        $this->assertPair(null, $case, $proxy);
    }

    /**
     * 自分と代理元が別のノードに当たる・代理元 2 人が別のノードに当たる(同期の 2 つの枝)と組が 2 つで選べない。代理が無ければ 1 つで選べる。
     */
    public function test_pairs_on_different_nodes_are_not_selectable(): void
    {
        $this->addVersion($this->r2());
        [$a1, $b1, $proxy] = $this->seededUsers('u0002', 'u0004', 'u0005');
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->assertPair(new BulkApproval($this->caseNode($case, 'a1')->id, 'a1', null), $case, $a1);

        $this->addProxy($b1, $a1);
        $this->addProxy($a1, $proxy);
        $this->addProxy($b1, $proxy);

        $this->assertPair(null, $case, $a1);
        $this->assertPair(null, $case, $proxy);
    }

    /**
     * 指定するノード(指定の入力が要る)は選べない。
     */
    public function test_a_designating_node_is_not_selectable(): void
    {
        $this->addVersion($this->r3());
        $case = $this->applyAs($this->seededUser('u0001'));

        $this->assertPair(null, $case, $this->seededUser('u0002'));
    }

    /**
     * 申請者承認防止に当たる組は選べない(申請者の代理先が本人として)。代理が無ければ選べる。
     */
    public function test_applicant_approval_prevention_is_not_selectable(): void
    {
        $first = $this->approveNode('first', 'u0002', ['apply'], prevents: true);
        $this->addVersion([[$this->applyNode(), $first], $this->chain(['apply', 'first'])]);
        [$applicant, $approver] = $this->seededUsers('u0001', 'u0002');
        $case = $this->applyAs($applicant);
        $this->assertPair(new BulkApproval($this->caseNode($case, 'first')->id, 'first', null), $case, $approver);

        $this->addProxy($applicant, $approver);

        $this->assertPair(null, $case, $approver);
    }

    /**
     * 保留中のノードは保留者側(保留した本人と、その代理先が代理元として)だけが選べ、保留していない処理対象者は選べない。
     */
    public function test_a_held_node_is_selectable_only_by_the_hold_side(): void
    {
        $this->createTwoTargetFlow();
        [$holder, $other, $proxy] = $this->seededUsers('u0002', 'u0003', 'u0005');
        $this->addProxy($holder, $proxy);
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->holdOn($case, 'approval1', 'u0002');
        $nodeId = $this->caseNode($case, 'approval1')->id;

        $this->assertPair(new BulkApproval($nodeId, '承認1', null), $case, $holder);
        $this->assertPair(new BulkApproval($nodeId, '承認1', $holder->id), $case, $proxy);
        $this->assertPair(null, $case, $other);
    }

    /**
     * 一括確認で選べる = 確認の前提(自分が確認対象者の開いている確認がある)。確認対象者でない人・確認が閉じた後は選べない。
     */
    public function test_confirm_is_selectable_with_an_open_confirmation(): void
    {
        $this->addVersion($this->confirmingRoute());
        $case = $this->applyAs($this->seededUser('u0001'));
        [$confirmer, $other] = $this->seededUsers('u0010', 'u0003');
        $this->assertSame([false, false], [$this->canConfirm($case, $confirmer), $this->canConfirm($case, $other)]);

        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);

        $this->assertSame([true, false], [$this->canConfirm($case, $confirmer), $this->canConfirm($case, $other)]);
        $this->sendBack($this->seededUser('u0007'), $case, 'apply');
        $this->assertFalse($this->canConfirm($case, $confirmer));
    }

    /**
     * 問い合わせの数は案件の数に依らない(2 件と 20 件で同じ)。
     */
    public function test_queries_do_not_grow_with_the_cases(): void
    {
        $this->createFlow();
        [$applicant, $approver] = $this->seededUsers('u0001', 'u0002');
        $this->addProxy($this->seededUser('u0003'), $approver);
        $cases = array_map(fn () => $this->applyAs($applicant), range(1, 20));
        $count = fn (array $some) => count($this->recordedSql(fn () => $this->bulkApprovalsOf($approver, ...$some)));

        $this->assertSame($count(array_slice($cases, 0, 2)), $count($cases));
        $this->assertNotContains(null, $this->bulkApprovalsOf($approver, ...$cases));
    }

    /**
     * 行の組と処理の時の組が同じ値。
     */
    private function assertPair(?BulkApproval $expected, WorkflowCase $case, User $user): void
    {
        $this->assertEquals($expected, $this->bulkApprovalOf($case, $user), "{$user->code} の行の組");
        $this->assertEquals($expected, $this->processingApprovalOf($case, $user), "{$user->code} の処理の時の組");
    }

    private function canConfirm(WorkflowCase $case, User $user): bool
    {
        $requester = new Requester($user->id, null);
        $decider = app(CaseOperationDecider::class);
        $fresh = WorkflowCase::query()->findOrFail($case->id);
        $situation = $decider->situationsOf($requester, $this->operationDay(), [$fresh])[$case->id];

        return $decider->canConfirm($requester, $this->operationDay(), $situation);
    }

    /**
     * 承認 1 = u0002・u0003(どちらか 1 人で進む)、承認 2 = u0004 のフロー。
     */
    private function createTwoTargetFlow(): void
    {
        $this->createFlow(approvals: [
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            [$this->userCondition('u0004')],
        ]);
    }
}

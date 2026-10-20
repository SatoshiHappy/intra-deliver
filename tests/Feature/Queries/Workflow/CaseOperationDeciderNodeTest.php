<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\SendBackCase;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 操作するノードごとの表の判定 `permitsIn`(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節の 4・5、8 節の表)。期待は表の行から書いた:
 * 承認・承認終了・否認・差戻しは「ノードが承認待ち ∧ 自分がそのノードの処理対象者」、再申請・取止めは「申請のノードが再申請待ち ∧ 自分が処理対象者」、
 * 一時保存の上書き・削除はノードを問わない(本人の一時保存)。R2(同期の枝 a1 = u0002、b1 = u0004)で、現在のノードが 2 つの案件を使う。
 */
final class CaseOperationDeciderNodeTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    private const NODE_OPERATIONS = [WorkflowOperation::Approve, WorkflowOperation::ApproveEarly, WorkflowOperation::Reject, WorkflowOperation::SendBack];

    public function test_each_target_is_permitted_only_on_its_own_current_node(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $ids = $this->labelIds($case);

        foreach (self::NODE_OPERATIONS as $operation) {
            $this->assertSame(
                ['b1' => true, 'a1' => false, 'a2' => false, 'apply' => false, 'none' => false],
                $this->judgements($case, 'u0004', $operation, ['b1' => $ids['b1'], 'a1' => $ids['a1'], 'a2' => $ids['a2'], 'apply' => $ids['apply'], 'none' => null]),
                $operation->value,
            );
            $this->assertSame(['a1' => true, 'b1' => false], $this->judgements($case, 'u0002', $operation, ['a1' => $ids['a1'], 'b1' => $ids['b1']]), $operation->value);
        }
    }

    public function test_reapplying_and_withdrawing_are_judged_on_the_apply_node_waiting_for_reapplication(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $ids = $this->labelIds($case);
        app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0004')), $case->id, $this->revisionOf($case), $ids['b1'], $ids['apply'], null);

        foreach ([WorkflowOperation::Reapply, WorkflowOperation::Withdraw] as $operation) {
            $this->assertSame(['apply' => true, 'b1' => false, 'none' => false], $this->judgements($case, 'u0001', $operation, ['apply' => $ids['apply'], 'b1' => $ids['b1'], 'none' => null]), $operation->value);
            $this->assertSame(['apply' => false], $this->judgements($case, 'u0004', $operation, ['apply' => $ids['apply']]), $operation->value);
        }

        foreach (self::NODE_OPERATIONS as $operation) {
            $this->assertSame(['apply' => false], $this->judgements($case, 'u0001', $operation, ['apply' => $ids['apply']]), $operation->value);
        }
    }

    public function test_draft_operations_do_not_depend_on_a_node(): void
    {
        $this->createFlow();
        $draft = WorkflowCase::query()->findOrFail(app(CreateDraft::class)->execute($this->contextOf($this->seededUser('u0001')), self::FLOW_CODE, new DraftInput(null, null, [], [])));

        foreach ([WorkflowOperation::OverwriteDraft, WorkflowOperation::DeleteDraft, WorkflowOperation::Apply] as $operation) {
            $this->assertSame(['none' => true], $this->judgements($draft, 'u0001', $operation, ['none' => null]), $operation->value);
            $this->assertSame(['none' => false], $this->judgements($draft, 'u0002', $operation, ['none' => null]), $operation->value);
        }
    }

    /**
     * @param  array<string, int|null>  $nodeIds  名前 → 操作するノードの id(null = ノードを渡さない)
     * @return array<string, bool>
     */
    private function judgements(WorkflowCase $case, string $userCode, WorkflowOperation $operation, array $nodeIds): array
    {
        $decider = app(CaseOperationDecider::class);
        $requester = new Requester($this->seededUser($userCode)->id, null);
        $situation = $decider->situationsOf($requester, $this->operationDay(), [WorkflowCase::query()->findOrFail($case->id)])[$case->id];

        return array_map(fn (?int $nodeId) => $decider->permitsIn($operation, $requester, $this->operationDay(), $situation, $nodeId), $nodeIds);
    }
}

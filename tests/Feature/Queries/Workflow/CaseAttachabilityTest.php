<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\HoldCase;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseAttachability;
use App\Queries\Workflow\CaseOperationDecider;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 添付の可否の判定(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 1.1 節・2 節の表): 業務モジュール・フロー・ステップの AND。
 * フローの値は、申請の前はフローの今の値、申請の後は案件に写した値。申請の系統はステップを見ず、承認の系統は操作するノードの写した設定を見る。
 * 保留解除は加えて処理者が保留者側か(2 節の「保留解除」)。
 */
final class CaseAttachabilityTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;

    private const BEFORE_APPLICATION = [
        WorkflowOperation::SaveDraft, WorkflowOperation::OverwriteDraft, WorkflowOperation::Apply,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function settings(): array
    {
        return [
            '使用 × 使う' => [true, true],
            '使用 × 使わない' => [true, false],
            '不使用 × 使う' => [false, true],
            '不使用 × 使わない' => [false, false],
        ];
    }

    #[DataProvider('settings')]
    public function test_the_operations_before_the_application_read_the_current_settings(bool $module, bool $flow): void
    {
        $this->switchModule($module);
        $this->flow()->update(['is_attachment_enabled' => $flow]);

        foreach (self::BEFORE_APPLICATION as $operation) {
            $this->assertSame($module && $flow, $this->canAttach($operation), $operation->value);
        }
    }

    #[DataProvider('settings')]
    public function test_the_applicant_operations_after_the_application_read_the_copy(bool $module, bool $copy): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $case->update(['is_attachment_enabled' => $copy]);
        $this->flow()->update(['is_attachment_enabled' => ! $copy]);
        $this->switchModule($module);

        foreach ([WorkflowOperation::Reapply, WorkflowOperation::Withdraw] as $operation) {
            $this->assertSame($module && $copy, $this->canAttach($operation, $case), $operation->value);
        }

        foreach ([WorkflowOperation::DeleteDraft, WorkflowOperation::Confirm] as $operation) {
            $this->assertFalse($this->canAttach($operation, $case), $operation->value);
        }
    }

    public function test_the_approval_operations_read_the_step_setting_of_the_operated_node(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $node = $this->caseNode($case, 'approval1');
        $operations = [
            WorkflowOperation::Approve, WorkflowOperation::ApproveEarly, WorkflowOperation::Reject,
            WorkflowOperation::SendBack, WorkflowOperation::PullBack, WorkflowOperation::Hold,
            WorkflowOperation::Transfer,
        ];

        foreach ($operations as $operation) {
            $this->assertTrue($this->canAttach($operation, $case, $node), $operation->value);
        }

        $node->update(['prevents_attachment_add' => true]);

        foreach ($operations as $operation) {
            $this->assertFalse($this->canAttach($operation, $case, $node), $operation->value);
        }

        $this->assertTrue($this->canAttach(WorkflowOperation::PullBack, $case, $this->caseNode($case, 'apply')));
        $case->update(['is_attachment_enabled' => false]);
        $this->assertFalse($this->canAttach(WorkflowOperation::PullBack, $case, $this->caseNode($case, 'apply')));
    }

    /**
     * 保留解除は、状況の処理者が保留者側のときだけ付けられる(保留した人と、その人を処理者にした代理先)。処理者が保留者側でない形
     * (案件の管理者として解除する形)は偽。保留者側の人が案件の管理者を兼ねても、処理者が保留者側なので真。
     */
    public function test_a_release_reads_whether_the_processor_is_on_the_hold_side(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $node = $this->caseNode($case, 'approval1');
        $u0002 = $this->seededUser('u0002');
        $this->assertFalse($this->releasable($case, $node, 'u0002', $u0002->id));
        app(HoldCase::class)->execute($this->contextOf($u0002), $case->id, $this->revisionOf($case), $node->id, null);

        $this->assertTrue($this->releasable($case, $node, 'u0002', $u0002->id));
        $this->assertTrue($this->releasable($case, $node, 'u0004', $u0002->id));
        $this->assertFalse($this->releasable($case, $node, 'u0003', $this->seededUser('u0003')->id));
        $node->update(['prevents_attachment_add' => true]);
        $this->assertFalse($this->releasable($case, $node, 'u0002', $u0002->id));
    }

    public function test_a_case_or_a_node_that_does_not_fit_the_operation_is_a_caller_error(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $draft = WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $this->flow()->id]);
        $calls = [
            '申請の前に案件' => fn () => $this->canAttach(WorkflowOperation::OverwriteDraft, $draft),
            '申請の後に案件なし' => fn () => $this->canAttach(WorkflowOperation::Reapply),
            '承認にノードなし' => fn () => $this->canAttach(WorkflowOperation::Approve, $case),
            '保留解除に状況なし' => fn () => $this->canAttach(
                WorkflowOperation::Release, $case, $this->caseNode($case, 'approval1'),
            ),
        ];

        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail("{$label}: LogicException にならなかった");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function canAttach(
        WorkflowOperation $operation,
        ?WorkflowCase $case = null,
        ?WorkflowCaseNode $node = null,
    ): bool {
        return app(CaseAttachability::class)
            ->canAttach($operation, $this->flow(), $case?->refresh(), $node?->refresh());
    }

    /**
     * 判定を求める者 $requesterCode・処理者 $processorUserId で読んだ状況での、保留解除の可否。
     */
    private function releasable(
        WorkflowCase $case,
        WorkflowCaseNode $node,
        string $requesterCode,
        int $processorUserId,
    ): bool {
        $requester = new Requester($this->seededUser($requesterCode)->id, null);
        $case->refresh();
        $situation = app(CaseOperationDecider::class)
            ->situationsOf($requester, $this->operationDay(), [$case], $processorUserId)[$case->id];

        return app(CaseAttachability::class)
            ->canAttach(WorkflowOperation::Release, $this->flow(), $case, $node->refresh(), $situation);
    }

    private function flow(): WorkflowFlow
    {
        return WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole();
    }

    private function switchModule(bool $enabled): void
    {
        RecordModule::query()->where('code', 'project')->update(['is_attachment_enabled' => $enabled]);
    }
}

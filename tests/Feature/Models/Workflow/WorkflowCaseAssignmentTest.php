<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 指定の制約(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.5、直列化と検査.md 7 節の「指定」): 指定した履歴の行と指定されたノードは同じ案件のもの、
 * 同じ指定(指定されたノードと履歴の行)に同じ番号・同じ人を重ねない、番号は 1 以上。
 */
final class WorkflowCaseAssignmentTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_accepts_the_assignments_of_one_approval_in_order(): void
    {
        $first = WorkflowCaseAssignment::factory()->createOne();

        $second = WorkflowCaseAssignment::factory()->createOne([...$first->only(['workflow_case_id', 'workflow_case_history_id', 'workflow_case_node_id']), 'sequence' => 2]);

        $this->assertModelExists($second);
    }

    public function test_rejects_a_history_row_of_another_case(): void
    {
        $elsewhere = WorkflowCaseHistory::factory()->create();

        $this->assertRejectedBy(
            'workflow_case_assignments_workflow_case_id_workflow_case_history_id_foreign',
            fn () => WorkflowCaseAssignment::factory()->createOne(['workflow_case_history_id' => $elsewhere->id]),
        );
    }

    public function test_rejects_a_node_of_another_case(): void
    {
        $assignment = WorkflowCaseAssignment::factory()->createOne();
        $elsewhere = WorkflowCaseNode::factory()->designated()->createOne();

        $this->assertRejectedBy(
            'workflow_case_assignments_workflow_case_id_workflow_case_node_id_foreign',
            fn () => $assignment->update(['workflow_case_node_id' => $elsewhere->id]),
        );
    }

    public function test_rejects_the_same_sequence_twice_in_an_assignment(): void
    {
        $first = WorkflowCaseAssignment::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_assignments_workflow_case_node_id_workflow_case_history_id_sequence_unique',
            fn () => WorkflowCaseAssignment::factory()->createOne($first->only(['workflow_case_id', 'workflow_case_history_id', 'workflow_case_node_id', 'sequence'])),
        );
    }

    public function test_rejects_the_same_user_twice_in_an_assignment(): void
    {
        $first = WorkflowCaseAssignment::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_assignments_user_id_workflow_case_history_id_workflow_case_node_id_unique',
            fn () => WorkflowCaseAssignment::factory()->createOne([...$first->only(['workflow_case_id', 'workflow_case_history_id', 'workflow_case_node_id', 'user_id']), 'sequence' => 2]),
        );
    }

    public function test_rejects_a_sequence_below_one(): void
    {
        $this->assertRejectedBy('workflow_case_assignments_sequence_check', fn () => WorkflowCaseAssignment::factory()->createOne(['sequence' => 0]));
    }
}

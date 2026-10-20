<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 確認対象者と確認の制約(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.10 節・3.11 節、同じ文書群の README.md の 7 節):
 * ノードと履歴の行は同じ案件のもの、同じノードに同じ確認対象者を重ねない、確認した人はそのノードの確認対象者。確認は何度でもできる(一意を置かない)。
 */
final class WorkflowCaseConfirmationTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_confirm_target_on_a_node_of_another_case(): void
    {
        $node = WorkflowCaseNode::factory()->confirming()->createOne();
        $other = WorkflowCase::factory()->active()->createOne();

        $this->assertRejectedBy(
            'workflow_case_confirm_targets_workflow_case_id_workflow_case_node_id_foreign',
            fn () => WorkflowCaseConfirmTarget::factory()
                ->createOne(['workflow_case_id' => $other->id, 'workflow_case_node_id' => $node->id]),
        );
    }

    public function test_rejects_the_same_confirm_target_twice_on_one_node(): void
    {
        $target = WorkflowCaseConfirmTarget::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_confirm_targets_workflow_case_node_id_user_id_unique',
            fn () => WorkflowCaseConfirmTarget::factory()
                ->createOne($target->only(['workflow_case_id', 'workflow_case_node_id', 'user_id'])),
        );
    }

    public function test_rejects_a_confirmation_by_a_person_who_is_not_a_confirm_target(): void
    {
        $target = WorkflowCaseConfirmTarget::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_confirmations_workflow_case_node_id_user_id_foreign',
            fn () => WorkflowCaseConfirmation::factory()->createOne([
                ...$target->only(['workflow_case_id', 'workflow_case_node_id']),
                'user_id' => User::factory()->createOne()->id,
            ]),
        );
    }

    public function test_rejects_a_confirmation_on_a_node_of_another_case(): void
    {
        $target = WorkflowCaseConfirmTarget::factory()->createOne();
        $other = WorkflowCase::factory()->active()->createOne();
        $node = WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $other->id]);
        $history = WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $node->id]);

        $this->assertRejectedBy(
            'workflow_case_confirmations_workflow_case_id_workflow_case_node_id_foreign',
            fn () => WorkflowCaseConfirmation::factory()->createOne([
                ...$target->only(['workflow_case_node_id', 'user_id']),
                'workflow_case_id' => $other->id,
                'workflow_case_history_id' => $history->id,
            ]),
        );
    }

    public function test_rejects_a_confirmation_pointing_to_a_history_row_of_another_case(): void
    {
        $target = WorkflowCaseConfirmTarget::factory()->createOne();
        $elsewhere = WorkflowCaseHistory::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_confirmations_workflow_case_id_workflow_case_history_id_foreign',
            fn () => WorkflowCaseConfirmation::factory()->createOne([
                ...$target->only(['workflow_case_id', 'workflow_case_node_id', 'user_id']),
                'workflow_case_history_id' => $elsewhere->id,
            ]),
        );
    }

    public function test_accepts_the_same_person_confirming_the_same_node_twice(): void
    {
        $first = WorkflowCaseConfirmation::factory()->createOne();
        $second = WorkflowCaseConfirmation::factory()->createOne(
            $first->only(['workflow_case_id', 'workflow_case_node_id', 'workflow_case_history_id', 'user_id']),
        );

        $this->assertSame(2, WorkflowCaseConfirmation::query()->whereKey([$first->id, $second->id])->count());
    }
}

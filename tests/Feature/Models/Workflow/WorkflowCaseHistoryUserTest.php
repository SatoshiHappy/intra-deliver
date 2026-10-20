<?php

namespace Tests\Feature\Models\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 履歴の人の制約(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.9 節、同じ文書群の README.md の 7 節): 履歴の行は同じ案件のもの、
 * 同じ履歴の行に同じ番号・同じ人を重ねない、番号は 1 から。
 */
final class WorkflowCaseHistoryUserTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_history_row_of_another_case(): void
    {
        $history = WorkflowCaseHistory::factory()->createOne();
        $other = WorkflowCase::factory()->active()->createOne();

        $this->assertRejectedBy(
            'workflow_case_history_users_workflow_case_id_workflow_case_history_id_foreign',
            fn () => WorkflowCaseHistoryUser::factory()
                ->createOne(['workflow_case_id' => $other->id, 'workflow_case_history_id' => $history->id]),
        );
    }

    public function test_rejects_the_same_sequence_twice_on_one_history_row(): void
    {
        $first = WorkflowCaseHistoryUser::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_history_users_workflow_case_history_id_sequence_unique',
            fn () => WorkflowCaseHistoryUser::factory()->createOne($this->sameHistory($first)),
        );
    }

    public function test_rejects_the_same_user_twice_on_one_history_row(): void
    {
        $first = WorkflowCaseHistoryUser::factory()->createOne();

        $this->assertRejectedBy(
            'workflow_case_history_users_user_id_workflow_case_history_id_unique',
            fn () => WorkflowCaseHistoryUser::factory()
                ->createOne([...$this->sameHistory($first), 'sequence' => 2, 'user_id' => $first->user_id]),
        );
    }

    public function test_rejects_the_sequence_zero(): void
    {
        $this->assertRejectedBy(
            'workflow_case_history_users_sequence_check',
            fn () => WorkflowCaseHistoryUser::factory()->createOne(['sequence' => 0]),
        );
    }

    public function test_accepts_people_in_order_on_one_history_row(): void
    {
        $first = WorkflowCaseHistoryUser::factory()->createOne();
        $second = WorkflowCaseHistoryUser::factory()
            ->createOne([...$this->sameHistory($first), 'sequence' => 2, 'user_id' => User::factory()]);

        $this->assertSame([1, 2], [$first->refresh()->sequence, $second->refresh()->sequence]);
    }

    /**
     * 渡した行と同じ案件・同じ履歴の行。
     *
     * @return array{workflow_case_id: int, workflow_case_history_id: int}
     */
    private function sameHistory(WorkflowCaseHistoryUser $row): array
    {
        return [
            'workflow_case_id' => $row->workflow_case_id,
            'workflow_case_history_id' => $row->workflow_case_history_id,
        ];
    }
}

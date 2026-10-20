<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 履歴の制約(docs の 設計/ワークフロー/データモデル/表.md 2.8、直列化と検査.md 6 節の「履歴」): 処理したノードと差戻し先は同じ案件のノード、
 * 差戻し ⇔ 差戻し先、差戻し先は差戻し元と違う、コメントは空文字にしない。申請・再申請 ⇒ 更新履歴の版を指す
 * (設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。
 * 段 3a(ルートの深さ/データモデル/案件の表.md 3.7、直列化と検査.md 7 節の「履歴」): 引戻し ⇔ node_changes が無い、引戻し ⇔ 引戻した行がある、
 * node_changes は配列、引戻した行は同じ案件の前の行、同じ行を 2 回引戻さない。
 */
final class WorkflowCaseHistoryTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const SEND_BACK = 'workflow_case_histories_action_send_back_workflow_case_node_id_check';

    private const REVISION = 'workflow_case_histories_action_record_revision_id_check';

    private const NODE_CHANGES = 'workflow_case_histories_action_node_changes_check';

    private const REVERTED = 'workflow_case_histories_action_reverted_workflow_case_history_id_check';

    public function test_rejects_a_row_whose_node_belongs_to_another_case(): void
    {
        $node = WorkflowCaseNode::factory()->create();
        $other = WorkflowCase::factory()->active()->create();

        $this->assertRejectedBy(
            'workflow_case_histories_workflow_case_id_workflow_case_node_id_foreign',
            fn () => WorkflowCaseHistory::factory()->create(['workflow_case_node_id' => $node->id, 'workflow_case_id' => $other->id]),
        );
    }

    public function test_rejects_a_send_back_to_a_node_of_another_case(): void
    {
        $elsewhere = WorkflowCaseNode::factory()->create();

        $this->assertRejectedBy(
            'workflow_case_histories_workflow_case_id_send_back_workflow_case_node_id_foreign',
            fn () => WorkflowCaseHistory::factory()->sendBack()->create(['send_back_workflow_case_node_id' => $elsewhere->id]),
        );
    }

    public function test_accepts_a_send_back_to_an_earlier_node_of_the_case(): void
    {
        $history = WorkflowCaseHistory::factory()->sendBack()->create();

        $this->assertSame(WorkflowAction::SendBack, $history->refresh()->action);
    }

    public function test_rejects_a_send_back_without_a_destination(): void
    {
        $this->assertRejectedBy(self::SEND_BACK, fn () => WorkflowCaseHistory::factory()->sendBack()->create(['send_back_workflow_case_node_id' => null]));
    }

    public function test_rejects_a_destination_on_a_row_that_is_not_a_send_back(): void
    {
        $sendBack = WorkflowCaseHistory::factory()->sendBack()->create();

        $this->assertRejectedBy(self::SEND_BACK, fn () => $sendBack->update(['action' => WorkflowAction::Approve]));
    }

    public function test_rejects_a_send_back_to_its_own_node(): void
    {
        $sendBack = WorkflowCaseHistory::factory()->sendBack()->create();

        $this->assertRejectedBy(
            'workflow_case_histories_workflow_case_node_id_send_back_workflow_case_node_id_check',
            fn () => $sendBack->update(['send_back_workflow_case_node_id' => $sendBack->workflow_case_node_id]),
        );
    }

    public function test_rejects_an_application_without_a_revision(): void
    {
        $application = ['action' => WorkflowAction::Apply, 'record_revision_id' => null];
        $this->assertRejectedBy(self::REVISION, fn () => WorkflowCaseHistory::factory()->create($application));
    }

    public function test_rejects_a_reapplication_without_a_revision(): void
    {
        $reapplication = ['action' => WorkflowAction::Reapply, 'record_revision_id' => null];
        $this->assertRejectedBy(self::REVISION, fn () => WorkflowCaseHistory::factory()->create($reapplication));
    }

    /**
     * 処理の行は版を指さなくても、指しても(ステップの処理で添付を付けた行)通る。
     */
    public function test_accepts_an_approval_with_or_without_a_revision(): void
    {
        $approval = WorkflowCaseHistory::factory()->create(['action' => WorkflowAction::Approve]);
        $this->assertNull($approval->record_revision_id);

        $approval->update(['record_revision_id' => RecordRevision::factory()->update()->create()->id]);

        $this->assertNotNull($approval->refresh()->record_revision_id);
    }

    public function test_rejects_an_empty_comment(): void
    {
        $this->assertRejectedBy('workflow_case_histories_comment_check', fn () => WorkflowCaseHistory::factory()->create(['comment' => '']));
    }

    public function test_accepts_a_pull_back_of_an_earlier_row_of_the_case(): void
    {
        $pullBack = WorkflowCaseHistory::factory()->pullBack()->create();

        $this->assertSame([WorkflowAction::PullBack, null], [$pullBack->refresh()->action, $pullBack->node_changes]);
        $this->assertLessThan($pullBack->id, $pullBack->reverted_workflow_case_history_id);
    }

    public function test_rejects_a_pull_back_with_node_changes(): void
    {
        $this->assertRejectedBy(self::NODE_CHANGES, fn () => WorkflowCaseHistory::factory()->pullBack()->create(['node_changes' => []]));
    }

    public function test_rejects_a_pull_back_without_a_reverted_row(): void
    {
        $this->assertRejectedBy(self::REVERTED, fn () => WorkflowCaseHistory::factory()->pullBack()->create(['reverted_workflow_case_history_id' => null]));
    }

    public function test_rejects_a_row_that_is_not_a_pull_back_without_node_changes(): void
    {
        $this->assertRejectedBy(self::NODE_CHANGES, fn () => WorkflowCaseHistory::factory()->create(['action' => WorkflowAction::Approve, 'node_changes' => null]));
    }

    public function test_rejects_a_reverted_row_on_a_row_that_is_not_a_pull_back(): void
    {
        $earlier = WorkflowCaseHistory::factory()->create();

        $this->assertRejectedBy(self::REVERTED, fn () => WorkflowCaseHistory::factory()->create(['workflow_case_node_id' => $earlier->workflow_case_node_id, 'reverted_workflow_case_history_id' => $earlier->id]));
    }

    public function test_rejects_node_changes_that_are_not_an_array(): void
    {
        $this->assertRejectedBy('workflow_case_histories_node_changes_check', fn () => WorkflowCaseHistory::factory()->create(['node_changes' => ['node' => 1]]));
    }

    public function test_rejects_reverting_a_later_row(): void
    {
        $pullBack = WorkflowCaseHistory::factory()->pullBack()->create();
        $later = WorkflowCaseHistory::factory()->create(['workflow_case_node_id' => $pullBack->workflow_case_node_id]);

        $this->assertRejectedBy(
            'workflow_case_histories_reverted_workflow_case_history_id_id_check',
            fn () => $pullBack->update(['reverted_workflow_case_history_id' => $later->id]),
        );
    }

    public function test_rejects_reverting_the_same_row_twice(): void
    {
        $pullBack = WorkflowCaseHistory::factory()->pullBack()->create();

        $this->assertRejectedBy(
            'workflow_case_histories_reverted_workflow_case_history_id_unique',
            fn () => WorkflowCaseHistory::factory()->pullBack()->create(['workflow_case_node_id' => $pullBack->workflow_case_node_id, 'reverted_workflow_case_history_id' => $pullBack->reverted_workflow_case_history_id]),
        );
    }

    public function test_rejects_reverting_a_row_of_another_case(): void
    {
        $elsewhere = WorkflowCaseHistory::factory()->create();
        $pullBack = WorkflowCaseHistory::factory()->pullBack()->create();

        $this->assertRejectedBy(
            'workflow_case_histories_workflow_case_id_reverted_workflow_case_history_id_foreign',
            fn () => $pullBack->update(['reverted_workflow_case_history_id' => $elsewhere->id]),
        );
    }
}

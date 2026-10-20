<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CurrentAssignments;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 今の指定(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.5): 指定されたノード Y の指定するノード X の、引戻されていない最新の承認の行の、
 * Y の行の集まり(指定の順)。行が 0 = 0 人。X の承認が無い Y は入らない。
 */
final class CurrentAssignmentsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;

    public function test_the_latest_approval_of_the_designator_that_is_not_pulled_back_decides(): void
    {
        [$u0002, $u0003, $u0004, $u0010] = $this->seededUsers('u0002', 'u0003', 'u0004', 'u0010');
        [$case, $designator, $designated] = $this->designatedPair();
        $first = $this->approve($designator, $u0002);
        $this->assign($first, $designated, $u0004, $u0003);
        $this->assertSame([$designated->id => [$u0004->id, $u0003->id]], $this->assignments($case));

        $second = $this->approve($designator, $u0002);
        $this->assign($second, $designated, $u0010);
        $this->assertSame([$designated->id => [$u0010->id]], $this->assignments($case));

        WorkflowCaseHistory::factory()->pullBack()->createOne(['workflow_case_node_id' => $designator->id, 'user_id' => $u0002->id, 'reverted_workflow_case_history_id' => $second->id]);
        $this->assertSame([$designated->id => [$u0004->id, $u0003->id]], $this->assignments($case));
    }

    public function test_an_approval_without_rows_for_the_node_is_zero_people(): void
    {
        [$case, $designator, $designated] = $this->designatedPair();
        $this->approve($designator, $this->seededUsers('u0002')[0]);

        $this->assertSame([$designated->id => []], $this->assignments($case));
    }

    public function test_a_node_whose_designator_has_not_approved_is_left_out(): void
    {
        [$case, $designator] = $this->designatedPair();
        WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $designator->id, 'action' => WorkflowAction::Reject]);

        $this->assertSame([], $this->assignments($case));
        $this->assertSame([], app(CurrentAssignments::class)->of(WorkflowCase::factory()->active()->createOne()->id));
    }

    /**
     * @return array{WorkflowCase, WorkflowCaseNode, WorkflowCaseNode} 案件・指定するノード X・指定されたノード Y(縦配置)
     */
    private function designatedPair(): array
    {
        $case = WorkflowCase::factory()->active()->createOne();
        WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $case->id, 'position' => 1]);
        $designated = WorkflowCaseNode::factory()->designated(WorkflowNodeKind::Parallel)->createOne(['workflow_case_id' => $case->id, 'max_assignees' => 5]);

        return [$case, WorkflowCaseNode::query()->findOrFail($designated->designator_workflow_case_node_id), $designated];
    }

    private function approve(WorkflowCaseNode $designator, User $actor): WorkflowCaseHistory
    {
        return WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $designator->id, 'user_id' => $actor->id, 'action' => WorkflowAction::Approve]);
    }

    private function assign(WorkflowCaseHistory $approval, WorkflowCaseNode $designated, User ...$users): void
    {
        foreach (array_values($users) as $index => $user) {
            WorkflowCaseAssignment::factory()->createOne([
                'workflow_case_id' => $approval->workflow_case_id, 'workflow_case_history_id' => $approval->id, 'workflow_case_node_id' => $designated->id,
                'sequence' => $index + 1, 'user_id' => $user->id,
            ]);
        }
    }

    /**
     * @return array<int, list<int>>
     */
    private function assignments(WorkflowCase $case): array
    {
        return app(CurrentAssignments::class)->of($case->id);
    }
}

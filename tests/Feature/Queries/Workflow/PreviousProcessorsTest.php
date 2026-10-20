<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\PreviousProcessors;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 以前そのノードを処理した人(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4.5 の書き戻す文): 今そのノードが処理済みで指している履歴の行の
 * 処理者。前の回の行(後の行・引戻されていない行を含む)は見ず、処理済みでないノードは入れない(区切り 3-3 のレビューの #1)。
 */
final class PreviousProcessorsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;

    public function test_the_processor_of_the_row_a_done_node_points_to(): void
    {
        [$u0002, $u0003, $u0004] = $this->seededUsers('u0002', 'u0003', 'u0004');
        $case = WorkflowCase::factory()->active()->createOne();
        [, $approval, $waiting, $untouched] = $this->caseNodes($case, 4);
        $pointed = WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $approval->id, 'user_id' => $u0002->id, 'action' => WorkflowAction::Approve]);
        WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $approval->id, 'user_id' => $u0003->id, 'action' => WorkflowAction::Reject]);
        $approval->update(['state' => WorkflowNodeState::Done, 'workflow_case_history_id' => $pointed->id]);
        $this->process($waiting, $u0004, WorkflowAction::Approve);
        $this->reach($waiting, $u0003);

        $this->assertSame([$approval->id => $u0002->id], $this->processors([$approval, $waiting, $untouched]));
        $this->assertSame([], app(PreviousProcessors::class)->of([]));
    }

    /**
     * @param  list<WorkflowCaseNode>  $nodes
     * @return array<int, int>
     */
    private function processors(array $nodes): array
    {
        return app(PreviousProcessors::class)->of(array_map(fn (WorkflowCaseNode $node) => $node->id, $nodes));
    }
}

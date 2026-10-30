<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CurrentNode;
use App\Queries\Workflow\CurrentNodes;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 現在のノードの集合の口(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3): 状態が現在のノードの状態の一覧(承認待ち・再申請待ち)の
 * ノードだけ、並び = 親の position → 子の sequence、表示名 = 展開の行は「{親の名前} {子の名前}」(間は半角の空白 1 つ)、親の id と子の名前は展開の行だけ、処理対象者は昇順。
 */
final class CurrentNodesTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;

    public function test_lists_the_current_nodes_in_the_copied_order_with_display_names_and_targets(): void
    {
        [$u0002, $u0003, $u0004, $u0010] = $this->seededUsers('u0002', 'u0003', 'u0004', 'u0010');
        $case = WorkflowCase::factory()->active()->createOne();
        $this->node($case, 1, 'apply', '申請', WorkflowNodeKind::Apply, WorkflowNodeState::Reapply);
        $council = WorkflowCaseNode::factory()->designated(WorkflowNodeKind::Parallel)->createOne(['workflow_case_id' => $case->id, 'name' => '関係部署の合議', 'state' => WorkflowNodeState::Running, 'max_assignees' => 3]);
        $later = WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $case->id, 'name' => '責任者', 'state' => WorkflowNodeState::Waiting]);
        $third = $this->child($council, 3, WorkflowNodeState::Waiting);
        $this->child($council, 2, WorkflowNodeState::Skipped);
        $first = $this->child($council, 1, WorkflowNodeState::Waiting);
        $earlier = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->where('position', 2)->sole();
        $earlier->update(['name' => '課長', 'state' => WorkflowNodeState::Waiting]);
        $this->target($first, $u0004);
        $this->target($first, $u0003);
        $this->target($third, $u0010);
        $this->target($later, $u0002);

        $nodes = app(CurrentNodes::class)->of([$case->id])[$case->id];

        $this->assertSame([
            ['申請', 'apply', 'reapply', null, null, []],
            ['課長', 'approve', 'waiting', null, null, []],
            ['関係部署の合議 承認 1', 'approve', 'waiting', '関係部署の合議', 1, $this->sortedIds($u0003->id, $u0004->id)],
            ['関係部署の合議 承認 3', 'approve', 'waiting', '関係部署の合議', 3, [$u0010->id]],
            ['責任者', 'approve', 'waiting', null, null, [$u0002->id]],
        ], array_map(fn (CurrentNode $node) => [$node->name, $node->kind->value, $node->state->value, $node->parentName, $node->sequence, $node->targetUserIds], $nodes));
        $childNames = array_map(fn (CurrentNode $node) => $node->childName, $nodes);
        $this->assertSame([null, null, '承認 1', '承認 3', null], $childNames);
        $parentIds = array_map(fn (CurrentNode $node) => $node->parentId, $nodes);
        $this->assertSame([null, null, $council->id, $council->id, null], $parentIds);
        $this->assertSame($first->id, $nodes[2]->id);
    }

    public function test_maps_every_case_and_a_case_without_current_nodes_to_an_empty_list(): void
    {
        $waiting = WorkflowCase::factory()->active()->createOne();
        $node = WorkflowCaseNode::factory()->current()->createOne(['workflow_case_id' => $waiting->id, 'position' => 1]);
        $completed = WorkflowCase::factory()->completed()->createOne();
        WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $completed->id, 'position' => 1, 'state' => WorkflowNodeState::Unreached]);

        $result = app(CurrentNodes::class)->of([$completed->id, $waiting->id]);

        $this->assertSame([$completed->id, $waiting->id], array_keys($result));
        $this->assertSame([], $result[$completed->id]);
        $this->assertSame([$node->id], array_map(fn (CurrentNode $current) => $current->id, $result[$waiting->id]));
        $this->assertSame([], app(CurrentNodes::class)->of([]));
    }

    public function test_the_display_name_of_a_node_that_is_not_an_expansion_row_is_its_copied_name(): void
    {
        $node = WorkflowCaseNode::factory()->createOne(['name' => '部長']);

        $this->assertSame('部長', $node->displayName());
    }

    private function node(WorkflowCase $case, int $position, string $code, string $name, WorkflowNodeKind $kind, WorkflowNodeState $state): WorkflowCaseNode
    {
        return WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $case->id, 'position' => $position, 'code' => $code, 'name' => $name, 'kind' => $kind, 'state' => $state]);
    }

    private function child(WorkflowCaseNode $parent, int $sequence, WorkflowNodeState $state): WorkflowCaseNode
    {
        return WorkflowCaseNode::factory()->child()->createOne([
            'workflow_case_id' => $parent->workflow_case_id, 'parent_workflow_case_node_id' => $parent->id, 'sequence' => $sequence, 'name' => "承認 {$sequence}", 'state' => $state,
        ]);
    }

    /**
     * @return list<int>
     */
    private function sortedIds(int ...$ids): array
    {
        $ids = array_values($ids);
        sort($ids);

        return $ids;
    }
}

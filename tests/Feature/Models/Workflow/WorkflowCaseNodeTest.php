<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use Database\Factories\Workflow\WorkflowCaseNodeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件のノードと処理対象者の制約(docs の 設計/ワークフロー/データモデル/表.md 2.6・2.7、直列化と検査.md 6 節の「案件のノード」「処理対象者」と、段 3a の
 * ルートの深さ/データモデル/案件の表.md 3.2、直列化と検査.md 7 節の「案件のノード」): 種類と状態の組、処理済み ⇔ 履歴を指す、指す履歴はそのノードのもの、
 * 展開の行の形(親・番号を持ち、位置・コードを持たず、承認で指定の種類なし)、同じ親の同じ番号、親は同じ案件のノード、同じノードに同じユーザーの処理対象者は 1 行。
 * 現在のノードは案件に 2 つ以上持てる(段 2 の部分一意索引を消した = 現在のノードは集合)。
 */
final class WorkflowCaseNodeTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * 種類と状態の組の外: 名前 → [種類, 状態, factory の状態]。
     *
     * @return array<string, array{WorkflowNodeKind, WorkflowNodeState, string|null}>
     */
    public static function kindsAndStatesOutsideTheTable(): array
    {
        return [
            '申請のノードの展開中' => [WorkflowNodeKind::Apply, WorkflowNodeState::Running, null],
            '申請のノードの省略' => [WorkflowNodeKind::Apply, WorkflowNodeState::Skipped, null],
            '承認のノードの通過' => [WorkflowNodeKind::Approve, WorkflowNodeState::Passed, null],
            '承認のノードの展開中' => [WorkflowNodeKind::Approve, WorkflowNodeState::Running, null],
            '縦配置の承認待ち' => [WorkflowNodeKind::Parallel, WorkflowNodeState::Waiting, 'designated'],
            '横配置の処理済み' => [WorkflowNodeKind::Serial, WorkflowNodeState::Done, 'designated'],
            '分岐開始の処理済み' => [WorkflowNodeKind::BranchStart, WorkflowNodeState::Done, 'branchStart'],
            '分岐開始の展開中' => [WorkflowNodeKind::BranchStart, WorkflowNodeState::Running, 'branchStart'],
        ];
    }

    /**
     * 種類と状態の組の中(段 3a で足した状態): 名前 → [種類, 状態, factory の状態]。
     *
     * @return array<string, array{WorkflowNodeKind, WorkflowNodeState, string|null}>
     */
    public static function kindsAndStatesInTheTable(): array
    {
        return [
            '承認のノードの省略' => [WorkflowNodeKind::Approve, WorkflowNodeState::Skipped, null],
            '横配置の展開中' => [WorkflowNodeKind::Serial, WorkflowNodeState::Running, 'designated'],
            '縦配置の通過' => [WorkflowNodeKind::Parallel, WorkflowNodeState::Passed, 'designated'],
            '縦配置の省略' => [WorkflowNodeKind::Parallel, WorkflowNodeState::Skipped, 'designated'],
            '分岐開始の通過' => [WorkflowNodeKind::BranchStart, WorkflowNodeState::Passed, 'branchStart'],
            '分岐開始の省略' => [WorkflowNodeKind::BranchStart, WorkflowNodeState::Skipped, 'branchStart'],
        ];
    }

    #[DataProvider('kindsAndStatesOutsideTheTable')]
    public function test_rejects_a_state_outside_the_states_of_its_kind(WorkflowNodeKind $kind, WorkflowNodeState $state, ?string $factoryState): void
    {
        $this->assertRejectedBy('workflow_case_nodes_kind_state_check', fn () => $this->nodeOf($kind, $factoryState)->createOne(['state' => $state]));
    }

    #[DataProvider('kindsAndStatesInTheTable')]
    public function test_accepts_a_state_in_the_states_of_its_kind(WorkflowNodeKind $kind, WorkflowNodeState $state, ?string $factoryState): void
    {
        $node = $this->nodeOf($kind, $factoryState)->createOne(['state' => $state]);

        $this->assertSame($state, $node->refresh()->state);
    }

    public function test_accepts_two_current_nodes_in_a_case(): void
    {
        $first = WorkflowCaseNode::factory()->current()->create(['position' => 2]);

        $second = WorkflowCaseNode::factory()->current()->create(['workflow_case_id' => $first->workflow_case_id, 'position' => 3]);

        $this->assertSame(
            [WorkflowNodeState::Waiting, WorkflowNodeState::Waiting],
            WorkflowCaseNode::query()->whereKey([$first->id, $second->id])->get()->map(fn (WorkflowCaseNode $node) => $node->state)->all(),
        );
    }

    /**
     * 展開の行の形に反する行: 名前 → [展開の行か, 上書きする列, 拒む制約(表の名前の後ろ)]。
     *
     * @return array<string, array{bool, array<string, mixed>, string}>
     */
    public static function malformedExpansionRows(): array
    {
        $columns = 'parent_workflow_case_node_id_sequence_code_position_check';

        return [
            '展開の行がコードを持つ' => [true, ['code' => 'child_node'], $columns],
            '展開の行が位置を持つ' => [true, ['position' => 5], $columns],
            '展開の行が番号を持たない' => [true, ['sequence' => null], $columns],
            '親の無い行が番号を持つ' => [false, ['position' => 2, 'sequence' => 1], $columns],
            '展開の行の番号 0' => [true, ['sequence' => 0], 'sequence_check'],
            '展開の行の種類が承認でない' => [true, ['kind' => WorkflowNodeKind::Serial], 'parent_workflow_case_node_id_kind_target_kind_check'],
            '展開の行が処理対象者の指定の種類を持つ' => [true, ['target_kind' => 'fixed', 'authz_subject_group_id' => null], 'parent_workflow_case_node_id_kind_target_kind_check'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('malformedExpansionRows')]
    public function test_rejects_a_malformed_expansion_row(bool $child, array $overrides, string $constraint): void
    {
        $factory = $child ? WorkflowCaseNode::factory()->child() : WorkflowCaseNode::factory();

        $this->assertRejectedBy("workflow_case_nodes_{$constraint}", fn () => $factory->createOne($overrides));
    }

    public function test_accepts_expansion_rows_under_a_parent(): void
    {
        $first = WorkflowCaseNode::factory()->child()->createOne();

        $second = WorkflowCaseNode::factory()->child()->createOne(['workflow_case_id' => $first->workflow_case_id, 'parent_workflow_case_node_id' => $first->parent_workflow_case_node_id, 'sequence' => 2, 'name' => '承認 2']);

        $this->assertSame([1, 2], WorkflowCaseNode::query()->where('parent_workflow_case_node_id', $first->parent_workflow_case_node_id)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertModelExists($second);
    }

    public function test_rejects_the_same_sequence_twice_under_a_parent(): void
    {
        $first = WorkflowCaseNode::factory()->child()->createOne();

        $this->assertRejectedBy(
            'workflow_case_nodes_parent_workflow_case_node_id_sequence_unique',
            fn () => WorkflowCaseNode::factory()->child()->createOne(['workflow_case_id' => $first->workflow_case_id, 'parent_workflow_case_node_id' => $first->parent_workflow_case_node_id]),
        );
    }

    public function test_rejects_a_parent_in_another_case(): void
    {
        $child = WorkflowCaseNode::factory()->child()->createOne();
        $elsewhere = WorkflowCaseNode::factory()->designated(WorkflowNodeKind::Serial)->createOne();

        $this->assertRejectedBy(
            'workflow_case_nodes_workflow_case_id_parent_workflow_case_node_id_foreign',
            fn () => $child->update(['parent_workflow_case_node_id' => $elsewhere->id]),
        );
    }

    public function test_accepts_a_current_node_beside_nodes_that_are_not_current(): void
    {
        $apply = WorkflowCaseNode::factory()->done()->create();
        WorkflowCaseNode::factory()->create(['workflow_case_id' => $apply->workflow_case_id, 'position' => 3]);

        $approve = WorkflowCaseNode::factory()->current()->create(['workflow_case_id' => $apply->workflow_case_id, 'position' => 2]);

        $this->assertSame(WorkflowNodeState::Waiting, $approve->refresh()->state);
    }

    /**
     * 種類のノード(位置 2 以上。factory の状態で、種類に合う処理対象者の指定・対の終了を持たせる)。
     */
    private function nodeOf(WorkflowNodeKind $kind, ?string $factoryState): WorkflowCaseNodeFactory
    {
        return match ($factoryState) {
            'designated' => WorkflowCaseNode::factory()->designated($kind),
            'branchStart' => WorkflowCaseNode::factory()->branchStart(),
            default => WorkflowCaseNode::factory()->state(['position' => $kind === WorkflowNodeKind::Apply ? 1 : 2, 'kind' => $kind]),
        };
    }

    public function test_rejects_an_apply_node_waiting_for_approval(): void
    {
        $this->assertRejectedBy('workflow_case_nodes_kind_state_check', fn () => WorkflowCaseNode::factory()->create(['position' => 1, 'state' => WorkflowNodeState::Waiting]));
    }

    public function test_rejects_an_approve_node_waiting_for_reapplication(): void
    {
        $this->assertRejectedBy('workflow_case_nodes_kind_state_check', fn () => WorkflowCaseNode::factory()->create(['position' => 2, 'state' => WorkflowNodeState::Reapply]));
    }

    public function test_rejects_a_done_node_without_a_history_row(): void
    {
        $this->assertRejectedBy('workflow_case_nodes_state_workflow_case_history_id_check', fn () => WorkflowCaseNode::factory()->create(['state' => WorkflowNodeState::Done]));
    }

    public function test_rejects_a_node_that_is_not_done_but_points_to_a_history_row(): void
    {
        $node = WorkflowCaseNode::factory()->create();
        $history = WorkflowCaseHistory::factory()->create(['workflow_case_node_id' => $node->id]);

        $this->assertRejectedBy('workflow_case_nodes_state_workflow_case_history_id_check', fn () => $node->update(['workflow_case_history_id' => $history->id]));
    }

    public function test_rejects_a_node_that_points_to_the_history_row_of_another_node_of_the_case(): void
    {
        $case = WorkflowCase::factory()->active()->create();
        $apply = WorkflowCaseNode::factory()->done()->create(['workflow_case_id' => $case->id]);
        $approve = WorkflowCaseNode::factory()->create(['workflow_case_id' => $case->id]);

        $this->assertRejectedBy(
            'workflow_case_nodes_id_workflow_case_history_id_foreign',
            fn () => $approve->update(['state' => WorkflowNodeState::Done, 'workflow_case_history_id' => $apply->workflow_case_history_id]),
        );
    }

    public function test_rejects_the_same_user_twice_as_a_target_of_a_node(): void
    {
        $target = WorkflowCaseNodeTarget::factory()->create();

        $this->assertRejectedBy(
            'workflow_case_node_targets_workflow_case_node_id_user_id_unique',
            fn () => WorkflowCaseNodeTarget::factory()->create(['workflow_case_node_id' => $target->workflow_case_node_id, 'user_id' => $target->user_id]),
        );
    }
}

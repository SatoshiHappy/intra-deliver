<?php

namespace Tests\Unit\Enums\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use PHPUnit\Framework\TestCase;

/**
 * 列挙の集まりのメソッド(種類・状態・履歴の種別の集まりの 1 か所 = docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 7 節)が、設計の表と同じ集まり。
 * 期待は設計の文から書く(G-2・G-4・G-5・G-9・G-10 = 定義の管理.md 3 節、済んだ・省く前 = 案件の操作/README.md 1.2・2.1、引戻せる種別 = 操作.md 10.5)。
 * 現在のノードの状態に保留を含む(設計/ワークフロー/案件の運用/案件の操作/README.md の 1.3 節)。
 */
final class WorkflowGroupsTest extends TestCase
{
    public function test_node_kind_groups_follow_the_definition_rules(): void
    {
        $values = fn (array $kinds) => array_map(fn (WorkflowNodeKind $kind) => $kind->value, $kinds);

        $this->assertSame(['apply', 'approve', 'serial', 'parallel'], $values(WorkflowNodeKind::targeted()));
        $this->assertSame(['branch_start', 'branch_end', 'sync_start', 'sync_end'], $values(WorkflowNodeKind::routing()));
        $this->assertSame(['approve', 'serial', 'parallel'], $values(WorkflowNodeKind::approving()));
        $this->assertSame(['serial', 'parallel'], $values(WorkflowNodeKind::expanding()));
        $this->assertSame(['branch_start', 'sync_start'], $values(WorkflowNodeKind::opening()));
        $this->assertSame(['apply', 'approve'], $values(WorkflowNodeKind::linear()));
        $this->assertSame(['branch_end', 'sync_end'], $values(WorkflowNodeKind::closing()));
        $this->assertSame(['approve', 'serial', 'parallel', 'branch_end', 'sync_end'], $values(WorkflowNodeKind::terminal()));
        $this->assertSame(['approve'], $values(WorkflowNodeKind::designating()));
        $this->assertSame(['branch_end', 'sync_end', 'parallel'], $values(WorkflowNodeKind::previousBlocking()));
    }

    public function test_an_opening_kind_pairs_with_the_closing_kind_of_the_same_family(): void
    {
        $pairs = [];

        foreach (WorkflowNodeKind::cases() as $kind) {
            $pairs[$kind->value] = $kind->closingKind()?->value;
        }

        $this->assertSame([
            'apply' => null, 'approve' => null, 'serial' => null, 'parallel' => null,
            'branch_start' => 'branch_end', 'branch_end' => null, 'sync_start' => 'sync_end', 'sync_end' => null,
        ], $pairs);
        $this->assertTrue(WorkflowNodeKind::Serial->isIn(WorkflowNodeKind::expanding()));
        $this->assertFalse(WorkflowNodeKind::Apply->isIn(WorkflowNodeKind::approving()));
    }

    public function test_node_state_groups_follow_the_case_operation_states(): void
    {
        $values = fn (array $states) => array_map(fn (WorkflowNodeState $state) => $state->value, $states);

        $this->assertSame(['done', 'passed', 'skipped'], $values(WorkflowNodeState::settled()));
        $this->assertSame(['waiting', 'reapply', 'hold'], $values(WorkflowNodeState::current()));
        $this->assertSame(
            ['unreached', 'running', 'waiting', 'reapply', 'hold'],
            $values(WorkflowNodeState::skippable()),
        );
        $this->assertSame(['done', 'passed'], $values(WorkflowNodeState::traversed()));
        $this->assertSame(['running', 'passed'], $values(WorkflowNodeState::expanded()));
        $this->assertSame(['unreached', 'running'], $values(WorkflowNodeState::passable()));
        $this->assertSame(
            ['unreached' => false, 'waiting' => false, 'reapply' => false, 'done' => true, 'running' => false, 'passed' => true, 'skipped' => true, 'hold' => false],
            array_combine($values(WorkflowNodeState::cases()), array_map(fn (WorkflowNodeState $state) => $state->isSettled(), WorkflowNodeState::cases())),
        );
    }

    public function test_pull_backable_actions_are_the_ones_that_do_not_complete_the_case(): void
    {
        $this->assertSame(['apply', 'reapply', 'approve', 'reject', 'send_back'], array_map(fn (WorkflowAction $action) => $action->value, WorkflowAction::pullBackable()));
        // 進むは承認と同じに数える(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節)
        $counted = array_map(fn (WorkflowAction $action) => $action->value, WorkflowAction::countsAsApproval());
        $this->assertSame(['approve', 'move_forward'], $counted);
    }
}

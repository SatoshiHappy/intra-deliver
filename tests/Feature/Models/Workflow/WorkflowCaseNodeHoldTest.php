<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件のノードの保留と到達日時の制約(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節、同じ文書群の README.md の 7 節):
 * 保留は承認のノード(展開の行を含む)だけ、現在のノード(承認待ち・再申請待ち・保留)は到達日時を持つ(到達していない・処理済みは NULL でよい)。
 */
final class WorkflowCaseNodeHoldTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const KIND_STATE = 'workflow_case_nodes_kind_state_check';

    private const REACHED_AT = 'workflow_case_nodes_state_reached_at_check';

    public function test_accepts_hold_on_an_approval_node_and_an_expansion_row(): void
    {
        $approval = WorkflowCaseNode::factory()->createOne(['position' => 2, 'state' => WorkflowNodeState::Hold]);
        $child = WorkflowCaseNode::factory()->child()->createOne(['state' => WorkflowNodeState::Hold]);

        $this->assertSame(WorkflowNodeState::Hold, $approval->refresh()->state);
        $this->assertSame(WorkflowNodeState::Hold, $child->refresh()->state);
    }

    /**
     * @return array<string, array{Closure(): WorkflowCaseNode}>
     */
    public static function nodesThatCannotBeHeld(): array
    {
        return [
            '申請のノード' => [fn (): WorkflowCaseNode => WorkflowCaseNode::factory()->createOne(['position' => 1])],
            '横配置' => [fn (): WorkflowCaseNode => self::placement(WorkflowNodeKind::Serial)],
            '縦配置' => [fn (): WorkflowCaseNode => self::placement(WorkflowNodeKind::Parallel)],
            '分岐のノード' => [fn (): WorkflowCaseNode => WorkflowCaseNode::factory()->branchStart()->createOne()],
        ];
    }

    /**
     * @param  Closure(): WorkflowCaseNode  $create
     */
    #[DataProvider('nodesThatCannotBeHeld')]
    public function test_rejects_hold_on_a_node_that_is_not_an_approval(Closure $create): void
    {
        $node = $create();

        $this->assertRejectedBy(self::KIND_STATE, fn () => $node->update(['state' => WorkflowNodeState::Hold]));
    }

    /**
     * @return array<string, array{int, WorkflowNodeState}>
     */
    public static function currentStates(): array
    {
        return [
            '承認待ち' => [2, WorkflowNodeState::Waiting],
            '再申請待ち' => [1, WorkflowNodeState::Reapply],
            '保留' => [2, WorkflowNodeState::Hold],
        ];
    }

    #[DataProvider('currentStates')]
    public function test_rejects_a_current_node_without_the_reached_time(int $position, WorkflowNodeState $state): void
    {
        $this->assertRejectedBy(
            self::REACHED_AT,
            fn () => WorkflowCaseNode::factory()
                ->createOne(['position' => $position, 'state' => $state, 'reached_at' => null]),
        );
    }

    public function test_accepts_no_reached_time_on_an_unreached_or_done_node(): void
    {
        $unreached = WorkflowCaseNode::factory()->createOne(['position' => 2, 'reached_at' => null]);
        $done = WorkflowCaseNode::factory()->done()->createOne(['position' => 2, 'reached_at' => null]);

        $this->assertSame(
            [[WorkflowNodeState::Unreached, null], [WorkflowNodeState::Done, null]],
            [[$unreached->refresh()->state, $unreached->reached_at], [$done->refresh()->state, $done->reached_at]],
        );
    }

    private static function placement(WorkflowNodeKind $kind): WorkflowCaseNode
    {
        return WorkflowCaseNode::factory()->designated($kind)->createOne();
    }
}

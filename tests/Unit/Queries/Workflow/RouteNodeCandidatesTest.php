<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Workflow\RouteGraph;
use App\Queries\Workflow\RouteNodeCandidates;
use PHPUnit\Framework\TestCase;

/**
 * 指定するノードと差戻しできる先の式(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節の G-10・G-11)。DB を使わずに確かめる
 * (定義の検査の判定が同じ式を呼ぶことは Feature の定義の検査とステップの設定のテスト)。
 * 形 = 申請 1 → 課長 2 → 分岐 3(部長 4 / 側 5)→ 分岐終了 6 → 合議 7(縦配置。課長が指定)→ 責任者 8(動的承認。課長が指定)→ 最後 9。
 */
final class RouteNodeCandidatesTest extends TestCase
{
    public function test_a_designator_is_an_approval_on_every_path_and_not_a_skippable_one(): void
    {
        $skippable = self::candidates(ownerMin: 0);
        $required = self::candidates(ownerMin: 1);

        $this->assertSame([2], $skippable->designatorsOf(9));
        $this->assertSame([2, 8], $required->designatorsOf(9));
        $this->assertSame([2], $required->designatorsOf(7));
        $this->assertSame([], $required->designatorsOf(2));
        $this->assertFalse($required->isDesignatorOf(9, 9));
        $this->assertFalse($required->isDesignatorOf(9, 4));
        $this->assertFalse($required->isDesignatorOf(9, 7));
    }

    public function test_the_send_back_targets_are_the_ancestors_that_have_assignees(): void
    {
        $candidates = self::candidates(ownerMin: 1);

        $this->assertSame([1, 2], $candidates->sendBackTargetsOf(4));
        $this->assertSame([1, 2, 4, 5], $candidates->sendBackTargetsOf(7));
        $this->assertSame([1, 2, 4, 5, 7, 8], $candidates->sendBackTargetsOf(9));
        $this->assertSame([], $candidates->sendBackTargetsOf(6));
        $this->assertSame([], $candidates->sendBackTargetsOf(1));
    }

    private static function candidates(int $ownerMin): RouteNodeCandidates
    {
        $nodes = [
            1 => self::node(1, WorkflowNodeKind::Apply, WorkflowTargetKind::Fixed),
            2 => self::node(2, WorkflowNodeKind::Approve, WorkflowTargetKind::Relative),
            3 => self::node(3, WorkflowNodeKind::BranchStart, null),
            4 => self::node(4, WorkflowNodeKind::Approve, WorkflowTargetKind::Relative),
            5 => self::node(5, WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed),
            6 => self::node(6, WorkflowNodeKind::BranchEnd, null),
            7 => self::node(7, WorkflowNodeKind::Parallel, WorkflowTargetKind::Designated, 2),
            8 => self::node(8, WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, $ownerMin),
            9 => self::node(9, WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed),
        ];
        $edges = [[1, 2], [2, 3], [3, 4], [3, 5], [4, 6], [5, 6], [6, 7], [7, 8], [8, 9]];

        return new RouteNodeCandidates($nodes, RouteGraph::of(array_keys($nodes), $edges));
    }

    /**
     * @param  positive-int  $id
     */
    private static function node(
        int $id,
        WorkflowNodeKind $kind,
        ?WorkflowTargetKind $targetKind,
        ?int $min = null,
    ): WorkflowRouteNode {
        $node = new WorkflowRouteNode([
            'code' => "n{$id}", 'kind' => $kind, 'target_kind' => $targetKind, 'min_assignees' => $min,
        ]);
        $node->id = $id;

        return $node;
    }
}

<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Queries\Workflow\CurrentNode;
use App\Queries\Workflow\CurrentNodes;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 案件の状態の札の規則(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節): 再申請待ち > すべて保留中 > 承認待ち。
 * 合議の子の片方だけが保留中なら承認待ち。現在のノードが無ければ札なし。
 */
final class CaseLabelStateTest extends TestCase
{
    /**
     * @return array<string, array{list<WorkflowNodeState>, WorkflowNodeState|null}>
     */
    public static function nodes(): array
    {
        [$waiting, $reapply, $hold] = [WorkflowNodeState::Waiting, WorkflowNodeState::Reapply, WorkflowNodeState::Hold];

        return [
            '承認待ち 1 つ' => [[$waiting], $waiting],
            '保留中 1 つ' => [[$hold], $hold],
            'すべて保留中' => [[$hold, $hold], $hold],
            '合議の子の片方だけ保留中' => [[$hold, $waiting], $waiting],
            '再申請待ちと保留中' => [[$hold, $reapply], $reapply],
            '再申請待ちと承認待ち' => [[$waiting, $reapply], $reapply],
            '現在のノードが無い' => [[], null],
        ];
    }

    /**
     * @param  list<WorkflowNodeState>  $states
     */
    #[DataProvider('nodes')]
    public function test_reapply_wins_then_all_held_then_waiting(array $states, ?WorkflowNodeState $expected): void
    {
        $nodes = array_map(fn (WorkflowNodeState $state, int $index) => new CurrentNode(
            id: $index + 1,
            name: "子 {$index}",
            kind: $state === WorkflowNodeState::Reapply ? WorkflowNodeKind::Apply : WorkflowNodeKind::Approve,
            state: $state,
            parentId: 100,
            parentName: '合議',
            childName: "子 {$index}",
            sequence: $index + 1,
            targetUserIds: [],
            reachedAt: CarbonImmutable::parse('2026-10-13 00:00:00'),
            dueOn: null,
        ), $states, array_keys($states));

        $this->assertSame($expected, CurrentNodes::labelStateOf($nodes));
    }
}

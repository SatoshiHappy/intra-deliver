<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseSituation;
use App\Queries\Workflow\CurrentNode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 現在のステップの文字列(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節の「現在のステップ」): 写した並びで表示名を「、」で並べ、
 * 同じ親(親のノードの id)の展開の行が続く所は「{親の名前}({子の名前}、…)」にまとめる(子が 1 つでも括弧)。保留中でも文字は変わらない。
 */
final class CaseCurrentNamesTest extends TestCase
{
    /**
     * 各ノード = [親の id, 親の名前, 名前(展開の行は子の名前), 状態]。
     *
     * @return array<string, array{list<array{int|null, string|null, string, WorkflowNodeState}>, string|null}>
     */
    public static function nodes(): array
    {
        [$waiting, $hold] = [WorkflowNodeState::Waiting, WorkflowNodeState::Hold];
        $council = '関係部署の合議';
        [$chief, $owner] = [[null, null, '課長', $waiting], [null, null, '責任者', $waiting]];

        return [
            '展開の行が無い(先頭は表示名、後ろは「、」+ 表示名)' => [[$chief, $owner], '課長、責任者'],
            '先頭の展開の行の子が 1 つ(最後で閉じる)' => [[[10, $council, '承認 1', $waiting]], '関係部署の合議(承認 1)'],
            '別のノードの後に同じ親の子が 2 つ、次の別のノードで閉じる' => [
                [$chief, [10, $council, '承認 1', $waiting], [10, $council, '承認 3', $waiting], $owner],
                '課長、関係部署の合議(承認 1、承認 3)、責任者',
            ],
            '違う親の子が続くと閉じて開き直す' => [
                [[10, $council, '承認 1', $waiting], [20, '事業部の配置', '承認 2', $waiting]],
                '関係部署の合議(承認 1)、事業部の配置(承認 2)',
            ],
            '同じ名前で別の親の子が続いても、親の id が違えば分ける' => [
                [[10, '合議', '承認 1', $waiting], [20, '合議', '承認 1', $waiting]],
                '合議(承認 1)、合議(承認 1)',
            ],
            '保留中の子も同じ文字' => [
                [[10, $council, '承認 1', $hold], [10, $council, '承認 2', $waiting]],
                '関係部署の合議(承認 1、承認 2)',
            ],
            '現在のノードが無い' => [[], null],
        ];
    }

    /**
     * @param  list<array{int|null, string|null, string, WorkflowNodeState}>  $nodes
     */
    #[DataProvider('nodes')]
    public function test_joins_names_and_groups_children_of_the_same_parent(array $nodes, ?string $expected): void
    {
        $current = array_map(fn (array $node, int $index) => new CurrentNode(
            id: $index + 1,
            name: $node[1] === null ? $node[2] : "{$node[1]} {$node[2]}",
            kind: WorkflowNodeKind::Approve,
            state: $node[3],
            parentId: $node[0],
            parentName: $node[1],
            childName: $node[1] === null ? null : $node[2],
            sequence: $node[0] === null ? null : $index + 1,
            targetUserIds: [],
            reachedAt: CarbonImmutable::parse('2026-10-13 00:00:00'),
            dueOn: null,
        ), $nodes, array_keys($nodes));

        $this->assertSame($expected, (new CaseSituation(new WorkflowCase, $current, [], [], null, []))->currentNames());
    }
}

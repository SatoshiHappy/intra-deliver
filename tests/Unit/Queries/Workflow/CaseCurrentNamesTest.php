<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseSituation;
use App\Queries\Workflow\CurrentNode;
use App\Queries\Workflow\CurrentNodes;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 現在のノードのまとめ方の 1 か所({@see CurrentNodes::groupsOf()})と、そこから作る 2 つの文字(期待は docs の
 * 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節の「現在のステップ」から手で書いた)。
 *
 * @phpstan-type CaseNodeRow array{int|null, string|null, string, WorkflowNodeState}
 */
final class CaseCurrentNamesTest extends TestCase
{
    /**
     * 各ノード = [親の id, 親の名前, 名前(展開の行は子の名前), 状態]。
     *
     * @return array<string, array{list<CaseNodeRow>, string|null, string|null}>
     */
    public static function nodes(): array
    {
        [$waiting, $hold] = [WorkflowNodeState::Waiting, WorkflowNodeState::Hold];
        $council = '関係部署の合議';
        [$chief, $owner] = [[null, null, '課長', $waiting], [null, null, '責任者', $waiting]];

        return [
            '展開の行が無い(先頭は表示名、後ろは「、」+ 表示名)' => [[$chief, $owner], '課長、責任者', '課長、責任者'],
            '先頭の展開の行の子が 1 つ(最後で閉じる)' => [[[10, $council, '承認 1', $waiting]], '関係部署の合議(承認 1)', '関係部署の合議(承認 1)'],
            '別のノードの後に同じ親の子が 2 つ、次の別のノードで閉じる' => [
                [$chief, [10, $council, '承認 1', $waiting], [10, $council, '承認 3', $waiting], $owner],
                '課長、関係部署の合議(承認 1、承認 3)、責任者',
                '課長、関係部署の合議(承認 1、承認 3)、責任者',
            ],
            '違う親の子が続くと閉じて開き直す' => [
                [[10, $council, '承認 1', $waiting], [20, '事業部の配置', '承認 2', $waiting]],
                '関係部署の合議(承認 1)、事業部の配置(承認 2)',
                '関係部署の合議(承認 1)、事業部の配置(承認 2)',
            ],
            '同じ名前で別の親の子が続いても、親の id が違えば分ける' => [
                [[10, '合議', '承認 1', $waiting], [20, '合議', '承認 1', $waiting]],
                '合議(承認 1)、合議(承認 1)',
                '合議(承認 1)、合議(承認 1)',
            ],
            '保留中の子は、名前だけの文字は同じで、suffix は子の名前の後に(保留中)' => [
                [[10, $council, '承認 1', $hold], [10, $council, '承認 2', $waiting]],
                '関係部署の合議(承認 1、承認 2)',
                '関係部署の合議(承認 1(保留中)、承認 2)',
            ],
            '並列の片方だけが保留中でも、そのノードの名前に添える' => [
                [$chief, [null, null, '部長', $hold]],
                '課長、部長',
                '課長、部長(保留中)',
            ],
            '保留中のノードが 1 つ' => [[[null, null, '部長', $hold]], '部長', '部長(保留中)'],
            '現在のノードが無い' => [[], null, null],
        ];
    }

    /**
     * @param  list<CaseNodeRow>  $nodes
     */
    #[DataProvider('nodes')]
    public function test_joins_names_and_groups_children_of_the_same_parent(
        array $nodes,
        ?string $names,
        ?string $withNodeHold,
    ): void {
        $current = self::currentNodes($nodes);

        $this->assertSame($names, (new CaseSituation(new WorkflowCase, $current, [], [], null, []))->currentNames());
        $this->assertSame($names, CurrentNodes::namesOf($current));
        $this->assertSame($withNodeHold, CurrentNodes::namesWithNodeHoldOf($current));
    }

    /**
     * 画面が描くまとまり: 展開の行でなければ親の名前 null でノード 1 つ、同じ親の子が続く所は 1 つにまとめ、ノードの名前は子の名前、hold は保留中。
     */
    public function test_the_node_groups_drawn_by_the_screens(): void
    {
        [$waiting, $hold] = [WorkflowNodeState::Waiting, WorkflowNodeState::Hold];
        $current = self::currentNodes([
            [null, null, '課長', $hold],
            [10, '関係部署の合議', '承認 1', $waiting],
            [10, '関係部署の合議', '承認 3', $hold],
            [null, null, '責任者', $waiting],
        ]);

        $this->assertSame([
            ['parentName' => null, 'nodes' => [['id' => 1, 'name' => '課長', 'hold' => true]]],
            ['parentName' => '関係部署の合議', 'nodes' => [
                ['id' => 2, 'name' => '承認 1', 'hold' => false],
                ['id' => 3, 'name' => '承認 3', 'hold' => true],
            ]],
            ['parentName' => null, 'nodes' => [['id' => 4, 'name' => '責任者', 'hold' => false]]],
        ], CurrentNodes::groupsOf($current));
        $this->assertSame([], CurrentNodes::groupsOf([]));
    }

    /**
     * 各ノード = [親の id, 親の名前, 名前(展開の行は子の名前), 状態] から、id を 1 から振った現在のノード。
     *
     * @param  list<CaseNodeRow>  $nodes
     * @return list<CurrentNode>
     */
    private static function currentNodes(array $nodes): array
    {
        return array_map(fn (array $node, int $index) => new CurrentNode(
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
    }
}

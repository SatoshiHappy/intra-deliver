<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * 現在のノードの集合を返す 1 か所の口(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3 = 保留を足すときの拡張点): 状態が現在のノードの状態の一覧
 * ({@see WorkflowNodeState::current()})に入るノード。並び = 写した並び({@see WorkflowCaseNode::compareInRoute()})。使い手 = 一覧・詳細・操作の判定・ホーム。
 * 閲覧の判定はしない(呼び手が先に絞る)。鍵を取らない。問い合わせは案件の数に依らない(ノード 1 回・処理対象者 1 回)。
 * 現在のノードのまとめ方({@see self::groupsOf()})と札の状態({@see self::labelStateOf()})も、ここが 1 か所。
 *
 * @phpstan-type GroupedNode array{id: int, name: string, hold: bool}
 * @phpstan-type CurrentNodeGroup array{parentName: string|null, nodes: non-empty-list<GroupedNode>}
 */
class CurrentNodes
{
    public function __construct(private CaseDeadlines $deadlines) {}

    /**
     * 案件ごとの現在のノード(現在のノードの無い案件は空の並び)。
     *
     * @param  list<int>  $caseIds
     * @return array<int, list<CurrentNode>> 案件の id → 現在のノードの並び(渡した順)
     *
     * @throws LogicException 現在のノードが到達日時を持たないとき(検査制約が止めるので起きない)
     */
    public function of(array $caseIds): array
    {
        $result = array_fill_keys($caseIds, []);

        if ($caseIds === []) {
            return $result;
        }

        $nodes = WorkflowCaseNode::query()->with('parent')->whereIn('workflow_case_id', $caseIds)->whereIn('state', WorkflowNodeState::current())->get();
        $targets = WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', $nodes->modelKeys())->orderBy('user_id')->get()->groupBy('workflow_case_node_id');
        $sorted = $nodes->sort(WorkflowCaseNode::compareInRoute(...));

        foreach ($sorted as $node) {
            $reachedAt = $node->reached_at === null
                ? throw new LogicException("現在のノード {$node->id} に到達日時が無い")
                : CarbonImmutable::instance($node->reached_at);
            $result[$node->workflow_case_id][] = new CurrentNode(
                id: $node->id,
                name: $node->displayName(),
                kind: $node->kind,
                state: $node->state,
                parentId: $node->parent?->id,
                parentName: $node->parent?->name,
                childName: $node->parent === null ? null : $node->name,
                sequence: $node->sequence,
                targetUserIds: array_values(array_map(fn (WorkflowCaseNodeTarget $target) => (int) $target->user_id, $targets->get($node->id)?->all() ?? [])),
                reachedAt: $reachedAt,
                dueOn: $this->deadlines->dueOn($reachedAt, $node->deadline_days),
            );
        }

        return $result;
    }

    /**
     * 案件の札の状態の 1 か所(規則 = docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節)。一覧・詳細・ホームで同じ申請の状態を違えないため、
     * どれもここを通る。現在のノードが無ければ null。
     *
     * @param  list<CurrentNode>  $nodes  1 つの案件の現在のノード
     */
    public static function labelStateOf(array $nodes): ?WorkflowNodeState
    {
        if ($nodes === []) {
            return null;
        }

        $states = array_map(fn (CurrentNode $node) => $node->state, $nodes);
        $notHeld = array_filter($states, fn (WorkflowNodeState $state) => $state !== WorkflowNodeState::Hold);

        return match (true) {
            in_array(WorkflowNodeState::Reapply, $states, true) => WorkflowNodeState::Reapply,
            $notHeld === [] => WorkflowNodeState::Hold,
            default => WorkflowNodeState::Waiting,
        };
    }

    /**
     * 現在のノードのまとまり(規則 = docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節の「現在のステップ」)。
     * 一覧の行・詳細の申請情報・持ち主の 1 行が同じまとめ方になるよう、画面はこの結果を描くだけにする。
     *
     * @param  list<CurrentNode>  $nodes  1 つの案件の現在のノード
     * @return list<CurrentNodeGroup>
     */
    public static function groupsOf(array $nodes): array
    {
        $groups = [];
        $group = [];

        foreach ($nodes as $node) {
            if ($group !== [] && ! self::sameParent($group[0], $node)) {
                $groups[] = self::groupOf($group);
                $group = [];
            }

            $group[] = $node;
        }

        if ($group !== []) {
            $groups[] = self::groupOf($group);
        }

        return $groups;
    }

    /**
     * 同じ親の展開の行か。
     */
    private static function sameParent(CurrentNode $first, CurrentNode $node): bool
    {
        return $node->parentId !== null && $node->parentId === $first->parentId;
    }

    /**
     * まとまり 1 つ。
     *
     * @param  non-empty-list<CurrentNode>  $group
     * @return CurrentNodeGroup
     */
    private static function groupOf(array $group): array
    {
        return [
            'parentName' => $group[0]->parentName,
            'nodes' => array_map(
                fn (CurrentNode $node) => [
                    'id' => $node->id,
                    'name' => $node->childName ?? $node->name,
                    'hold' => $node->state === WorkflowNodeState::Hold,
                ],
                $group,
            ),
        ];
    }

    /**
     * 名前だけの文字(保留中を添えない。無ければ null)。見出しの 1 行・ホームの節の語に使う。
     *
     * @param  list<CurrentNode>  $nodes  1 つの案件の現在のノード
     */
    public static function namesOf(array $nodes): ?string
    {
        return self::textOf(self::groupsOf($nodes), '');
    }

    /**
     * 保留中を文字で添えた文字(無ければ null)。札を描けない持ち主の 1 行の suffix に使う(docs の
     * 設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節)。
     *
     * @param  list<CurrentNode>  $nodes  1 つの案件の現在のノード
     */
    public static function namesWithNodeHoldOf(array $nodes): ?string
    {
        return self::textOf(self::groupsOf($nodes), '(保留中)');
    }

    /**
     * まとまりを文字にする(形 = 認可と一覧.md の 2.2 節)。保留中のノードの名前の後に $holdText。
     *
     * @param  list<CurrentNodeGroup>  $groups
     */
    private static function textOf(array $groups, string $holdText): ?string
    {
        if ($groups === []) {
            return null;
        }

        $texts = array_map(function (array $group) use ($holdText): string {
            $names = implode('、', array_map(
                fn (array $node) => $node['name'].($node['hold'] ? $holdText : ''),
                $group['nodes'],
            ));

            return $group['parentName'] === null ? $names : "{$group['parentName']}({$names})";
        }, $groups);

        return implode('、', $texts);
    }
}

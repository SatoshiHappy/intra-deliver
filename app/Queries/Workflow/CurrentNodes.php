<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;

/**
 * 現在のノードの集合を返す 1 か所の口(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3 = 保留を足すときの拡張点): 状態が現在のノードの状態の一覧
 * ({@see WorkflowNodeState::current()})に入るノード。並び = 写した並び({@see WorkflowCaseNode::compareInRoute()})。使い手 = 一覧・詳細・操作の判定・ホーム。
 * 閲覧の判定はしない(呼び手が先に絞る)。鍵を取らない。問い合わせは案件の数に依らない(ノード 1 回・処理対象者 1 回)。
 */
class CurrentNodes
{
    /**
     * 案件ごとの現在のノード(現在のノードの無い案件は空の並び)。
     *
     * @param  list<int>  $caseIds
     * @return array<int, list<CurrentNode>> 案件の id → 現在のノードの並び(渡した順)
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
            $result[$node->workflow_case_id][] = new CurrentNode(
                id: $node->id,
                name: $node->displayName(),
                kind: $node->kind,
                state: $node->state,
                parentName: $node->parent?->name,
                sequence: $node->sequence,
                targetUserIds: array_values(array_map(fn (WorkflowCaseNodeTarget $target) => (int) $target->user_id, $targets->get($node->id)?->all() ?? [])),
            );
        }

        return $result;
    }

    /**
     * 案件の札の状態の 1 か所(ルートの深さ/認可と一覧.md 2 節 = H1): 現在のノードに再申請待ちがあれば再申請待ち、無ければ承認待ち。現在のノードが無ければ null。
     * 一覧の行・詳細・ホームの件数の内訳と未処理の表が同じ規則を使う。
     *
     * @param  list<CurrentNode>  $nodes  1 つの案件の現在のノード
     */
    public static function labelStateOf(array $nodes): ?WorkflowNodeState
    {
        if ($nodes === []) {
            return null;
        }

        foreach ($nodes as $node) {
            if ($node->state === WorkflowNodeState::Reapply) {
                return WorkflowNodeState::Reapply;
            }
        }

        return WorkflowNodeState::Waiting;
    }
}

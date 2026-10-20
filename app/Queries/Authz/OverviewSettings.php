<?php

namespace App\Queries\Authz;

use App\Models\Authz\AuthzPolicy;

/**
 * 認可設定の画面の格子のセル = 効いている設定(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.2): 格子のタイプのポリシー
 * (すべてのアクション)を 1 回引き、木の先頭から下へ、行に直接置いたポリシーがあればそれ(inheritedFrom = null)、無ければ親の行のセルを置き場ごと写す
 * (親の行のセルが直接なら inheritedFrom = 親の行、継承なら親の行のセルの inheritedFrom)。上から下へ写すことは、判定の口
 * {@see AuthorizationQuery::explain()} の、下から親へたどって対象者ごとに最初に見つけることと同じ結果になる(一致は Feature テストが確かめる)。
 * 列に無い対象者(用途 selection)のポリシーは捨てる。設定の無いセルは入れない。並びは列の並び × アクションの並び。
 *
 * @phpstan-type GridSetting array{subjectGroupId: int, action: string, effect: string, inheritedFrom: array{code: string, name: string|null}|null}
 */
class OverviewSettings
{
    /**
     * 行ごとのセル(リソースグループ id → セル)。
     *
     * @param  list<OverviewTreeNode>  $nodes  木の深さ優先の並び(親が子より先)
     * @param  list<int>  $subjectGroupIds  列の対象者(列の並び)
     * @param  list<string>  $actions  タイプのアクション(列の並び)
     * @return array<int, list<GridSetting>>
     */
    public function settingsOf(string $resourceType, array $nodes, array $subjectGroupIds, array $actions): array
    {
        $direct = $this->directCells($resourceType, $subjectGroupIds);
        $nodesById = [];
        $cellsById = [];

        foreach ($nodes as $node) {
            $nodesById[$node->resourceGroupId] = $node;
            $inherited = $node->parentId === null ? [] : $this->inherited($cellsById[$node->parentId], $nodesById[$node->parentId]);
            $cellsById[$node->resourceGroupId] = array_replace($inherited, $direct[$node->resourceGroupId] ?? []);
        }

        return array_map(fn (array $cells) => $this->ordered($cells, $subjectGroupIds, $actions), $cellsById);
    }

    /**
     * 行に直接置いたセル(リソースグループ id → セルの鍵 → セル)。列の対象者のポリシーだけ。
     *
     * @param  list<int>  $subjectGroupIds
     * @return array<int, array<string, GridSetting>>
     */
    private function directCells(string $resourceType, array $subjectGroupIds): array
    {
        $cells = [];

        foreach (AuthzPolicy::query()->where('resource_type', $resourceType)->whereIn('authz_subject_group_id', $subjectGroupIds)->get() as $policy) {
            $cells[$policy->authz_resource_group_id][$this->key($policy->authz_subject_group_id, $policy->action)] = [
                'subjectGroupId' => $policy->authz_subject_group_id,
                'action' => $policy->action,
                'effect' => $policy->effect->value,
                'inheritedFrom' => null,
            ];
        }

        return $cells;
    }

    /**
     * 親の行のセルを子の行へ写したもの(置き場 = 親の行のセルの置き場。親の行に直接なら親の行)。
     *
     * @param  array<string, GridSetting>  $parentCells
     * @return array<string, GridSetting>
     */
    private function inherited(array $parentCells, OverviewTreeNode $parent): array
    {
        return array_map(fn (array $cell) => [
            ...$cell,
            'inheritedFrom' => $cell['inheritedFrom'] ?? ['code' => $parent->code, 'name' => $parent->name],
        ], $parentCells);
    }

    /**
     * セルを列の並び × アクションの並びに。
     *
     * @param  array<string, GridSetting>  $cells
     * @param  list<int>  $subjectGroupIds
     * @param  list<string>  $actions
     * @return list<GridSetting>
     */
    private function ordered(array $cells, array $subjectGroupIds, array $actions): array
    {
        $ordered = [];

        foreach ($subjectGroupIds as $subjectGroupId) {
            foreach ($actions as $action) {
                if (isset($cells[$this->key($subjectGroupId, $action)])) {
                    $ordered[] = $cells[$this->key($subjectGroupId, $action)];
                }
            }
        }

        return $ordered;
    }

    /**
     * セルの鍵(対象者とアクションの組)。
     */
    private function key(int $subjectGroupId, string $action): string
    {
        return "{$subjectGroupId}:{$action}";
    }
}

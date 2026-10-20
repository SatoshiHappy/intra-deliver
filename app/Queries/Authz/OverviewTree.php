<?php

namespace App\Queries\Authz;

use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Illuminate\Database\Eloquent\Collection;

/**
 * 認可設定の画面の格子の行(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.1): リソースタイプのリソースを持つリソースグループと、
 * その祖先(parent_id をたどった先頭まで)のすべて。並びは先頭(parent_id が NULL)からの深さ優先で、先頭の並びも兄弟の並びも sort_key・code。
 * 祖先へのたどりは同じリソースグループに 2 度目に届いたところで止める(循環は業務処理が止める)。行は先頭から下へたどって作るので、
 * 先頭から届かないリソースグループ(循環の中)は行にしない。親は 1 つなので、先頭から下へのたどりは同じリソースグループに 2 度届かない。
 */
class OverviewTree
{
    /**
     * @return list<OverviewTreeNode>
     */
    public function nodesOf(string $resourceType, string $localeCode): array
    {
        // 並びは DB の ORDER BY に任せる(メモリ上の比較は数字だけのコードを数値で比べ、DB の文字列の順と割れる)。
        // only()・groupBy() は元の並び(DB の順)を保つので、この後で並べ直さない。
        $groups = AuthzResourceGroup::query()->orderBy('sort_key')->orderBy('code')->get()->keyBy('id');
        $holderIds = array_values(AuthzResource::query()->where('resource_type', $resourceType)->get()->map(fn (AuthzResource $resource) => $resource->authz_resource_group_id)->all());
        $members = $groups->only($this->withAncestors($holderIds, $groups));
        $names = $this->names($localeCode);
        $childrenOf = $members->groupBy('parent_id');
        $isHolder = array_fill_keys($holderIds, true);
        $nodes = [];
        $stack = array_reverse(array_values($members->whereNull('parent_id')->map(fn (AuthzResourceGroup $group) => [$group, 0])->all()));

        while ($stack !== []) {
            [$group, $depth] = array_pop($stack);
            $nodes[] = new OverviewTreeNode($group->id, $group->parent_id, $depth, $group->code, $names[$group->id] ?? null, isset($isHolder[$group->id]));
            $children = $childrenOf->get($group->id, collect())->map(fn (AuthzResourceGroup $child) => [$child, $depth + 1]);
            array_push($stack, ...array_reverse(array_values($children->all())));
        }

        return $nodes;
    }

    /**
     * リソースグループと、その祖先のすべての id。祖先へのたどりは、既に入れたリソースグループに届いたところで止める
     * (その先の祖先は入れてある。循環でも終わる)。
     *
     * @param  list<int>  $resourceGroupIds
     * @param  Collection<int, AuthzResourceGroup>  $groups
     * @return list<int>
     */
    private function withAncestors(array $resourceGroupIds, Collection $groups): array
    {
        $members = [];

        foreach ($resourceGroupIds as $resourceGroupId) {
            $current = $groups->get($resourceGroupId);

            while ($current !== null && ! isset($members[$current->id])) {
                $members[$current->id] = true;
                $current = $current->parent_id === null ? null : $groups->get($current->parent_id);
            }
        }

        return array_keys($members);
    }

    /**
     * リソースグループのロケールの表示名(id → 表示名)。
     *
     * @return array<int, string|null>
     */
    private function names(string $localeCode): array
    {
        return AuthzResourceGroupTranslation::query()
            ->whereRelation('locale', 'code', $localeCode)
            ->get()
            ->mapWithKeys(fn (AuthzResourceGroupTranslation $translation) => [$translation->authz_resource_group_id => $translation->display_name])
            ->all();
    }
}

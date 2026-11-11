<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ServiceAction;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 認可設定の画面の格子の行と並び(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.1 の「行」「行の並び」「行の出し方の表」)と、
 * ルートの束の判定の相手(同じ文書の 2.2)。行は先頭から下へたどって作る(循環の中のリソースグループは行にならない)。
 */
class OverviewTree
{
    /**
     * @return list<OverviewTreeNode>
     */
    public function nodesOf(string $resourceType, string $localeCode): array
    {
        $translations = $this->translations($localeCode);

        return array_map(fn (array $row) => new OverviewTreeNode(
            $row[0]->id,
            $row[0]->parent_id,
            $row[1],
            $row[0]->code,
            $translations[$row[0]->id]->display_name ?? null,
            $translations[$row[0]->id]->description ?? null,
            $row[2],
        ), $this->rowsOf($resourceType));
    }

    /**
     * リソースグループがリソースタイプの格子の行か(行の作り方は {@see self::nodesOf()} と同じ)。
     */
    public function isRowOf(string $resourceType, int $resourceGroupId): bool
    {
        $rowIds = array_map(fn (array $row) => $row[0]->id, $this->rowsOf($resourceType));

        return in_array($resourceGroupId, $rowIds, true);
    }

    /**
     * 行のリソースグループと先頭からの深さ(深さ優先の並び)と、行の判定に使うリソースグループ(無ければ null)。
     *
     * @return list<array{AuthzResourceGroup, int, int|null}>
     */
    private function rowsOf(string $resourceType): array
    {
        // 並びは DB の ORDER BY に任せる(メモリ上の比較は数字だけのコードを数値で比べ、DB の文字列の順と割れる)。
        // only()・except()・groupBy()・array_filter() は元の並び(DB の順)を保つので、この後で sort_key・code を比べ直さない。
        $groups = AuthzResourceGroup::query()->orderBy('sort_key')->orderBy('code')->get()->keyBy('id');
        $resourceHolderIds = $this->resourceHolderIds($resourceType);
        $policyHolderIds = AuthzPolicy::query()->where('resource_type', $resourceType)->distinct()
            ->pluck('authz_resource_group_id')->all();
        $holderIds = [...$resourceHolderIds, ...array_values($policyHolderIds)];
        $hiddenIds = $this->hiddenIds($groups);
        $members = $groups->only($this->withAncestors($holderIds, $groups))->except($hiddenIds);
        $judgementSourceIds = $this->judgementSourceIds($groups, $hiddenIds, $resourceHolderIds);
        $childrenOf = $members->groupBy('parent_id');
        $parentIds = array_fill_keys(array_values(array_filter(
            $members->map(fn (AuthzResourceGroup $group) => $group->parent_id)->all(),
            fn (?int $parentId) => $parentId !== null,
        )), true);
        $rows = [];
        $stack = array_reverse($this->foldersFirst($members->whereNull('parent_id'), $parentIds, 0));

        while ($stack !== []) {
            [$group, $depth] = array_pop($stack);
            $rows[] = [$group, $depth, $judgementSourceIds[$group->id] ?? null];
            $children = $this->foldersFirst($childrenOf->get($group->id, collect()), $parentIds, $depth + 1);
            array_push($stack, ...array_reverse($children));
        }

        return $rows;
    }

    /**
     * 行にしないリソースグループの id(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.1 の「行の出し方の表」)。
     *
     * @param  Collection<int, AuthzResourceGroup>  $groups
     * @return list<int>
     */
    private function hiddenIds(Collection $groups): array
    {
        $routeIds = array_fill_keys($this->resourceHolderIds(ServiceAction::RESOURCE_TYPE), true);

        return array_values(array_filter($groups->keys()->all(), function (int $id) use ($groups, $routeIds) {
            $setCode = $this->setCodeOf($id, $groups);

            return $setCode === ServiceAction::UNMANAGED_SET_CODE
                || ($setCode === ServiceAction::SET_CODE && isset($routeIds[$id]));
        }));
    }

    /**
     * 行の判定の元のリソースグループ(行の id → 判定する id。規則 = 認可設定の画面.md 2.2。ルートの束は行にしない子から選ぶ)。
     *
     * @param  Collection<int, AuthzResourceGroup>  $groups  sort_key・code の並び
     * @param  list<int>  $hiddenIds
     * @param  list<int>  $resourceHolderIds
     * @return array<int, int>
     */
    private function judgementSourceIds(Collection $groups, array $hiddenIds, array $resourceHolderIds): array
    {
        $isHidden = array_fill_keys($hiddenIds, true);
        $judgementSourceIds = [];

        foreach (array_diff($resourceHolderIds, $hiddenIds) as $resourceGroupId) {
            $judgementSourceIds[$resourceGroupId] = $resourceGroupId;
        }

        $holdsResource = array_fill_keys($resourceHolderIds, true);

        return $judgementSourceIds + $this->firstHiddenHolderOfParents($groups, $isHidden, $holdsResource);
    }

    /**
     * 親の id → ルートの束の判定の元(行にしない子から選ぶ。並びは渡した $groups の順)。
     *
     * @param  Collection<int, AuthzResourceGroup>  $groups  sort_key・code の並び
     * @param  array<int, true>  $isHidden
     * @param  array<int, true>  $holdsResource
     * @return array<int, int>
     */
    private function firstHiddenHolderOfParents(Collection $groups, array $isHidden, array $holdsResource): array
    {
        $firsts = [];

        foreach ($groups as $group) {
            if ($group->parent_id !== null && isset($isHidden[$group->id], $holdsResource[$group->id])) {
                $firsts[$group->parent_id] ??= $group->id;
            }
        }

        return $firsts;
    }

    /**
     * リソースグループの先頭(parent_id をたどった先)のコード。たどりは同じリソースグループに 2 度目に届いたところで止める(循環 = null)。
     *
     * @param  Collection<int, AuthzResourceGroup>  $groups
     */
    private function setCodeOf(int $resourceGroupId, Collection $groups): ?string
    {
        $seen = [];
        $current = $groups->get($resourceGroupId);

        while ($current !== null && ! isset($seen[$current->id])) {
            if ($current->parent_id === null) {
                return $current->code;
            }

            $seen[$current->id] = true;
            $current = $groups->get($current->parent_id);
        }

        return null;
    }

    /**
     * 兄弟の行と深さ(並びの規則 = 認可設定の画面.md 2.1 の「行の並び」。子を持つかはこの格子の行の中で見る)。
     *
     * @param  SupportCollection<array-key, AuthzResourceGroup>  $siblings
     * @param  array<int, true>  $parentIds  この格子の行の中で子を持つ行の id
     * @return list<array{AuthzResourceGroup, int}>
     */
    private function foldersFirst(SupportCollection $siblings, array $parentIds, int $depth): array
    {
        $rows = array_values($siblings->map(fn (AuthzResourceGroup $group) => [$group, $depth])->all());
        $isFolder = fn (array $row) => isset($parentIds[$row[0]->id]);

        return [
            ...array_values(array_filter($rows, $isFolder)),
            ...array_values(array_filter($rows, fn (array $row) => ! $isFolder($row))),
        ];
    }

    /**
     * リソースタイプのリソースを持つリソースグループの id(判定する行)。
     *
     * @return list<int>
     */
    private function resourceHolderIds(string $resourceType): array
    {
        return array_values(AuthzResource::query()->where('resource_type', $resourceType)
            ->pluck('authz_resource_group_id')->all());
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
     * リソースグループのロケールの行(id → 表示名と説明)。
     *
     * @return array<int, AuthzResourceGroupTranslation>
     */
    private function translations(string $localeCode): array
    {
        return AuthzResourceGroupTranslation::query()
            ->whereRelation('locale', 'code', $localeCode)
            ->get()
            ->keyBy('authz_resource_group_id')
            ->all();
    }
}

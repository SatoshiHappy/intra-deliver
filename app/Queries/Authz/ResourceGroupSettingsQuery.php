<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ResourceGroupAttributeKey;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;

/**
 * 判定するリソースグループとアクションの設定({@see ResourceGroupSettings})を読み込む。リソースグループの数によらず決まった数の問い合わせで読む
 * (1 回の表示で多くのリソースグループを判定する画面のため = docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.2)。
 */
class ResourceGroupSettingsQuery
{
    /**
     * @param  list<int>  $resourceGroupIds
     * @param  list<string>  $actions
     */
    public function settingsOf(array $resourceGroupIds, array $actions): ResourceGroupSettings
    {
        $resourceTypes = AuthzResource::query()->whereIn('authz_resource_group_id', $resourceGroupIds)->get()
            ->mapWithKeys(fn (AuthzResource $resource) => [
                $resource->authz_resource_group_id => $resource->resource_type,
            ])
            ->all();

        if ($resourceTypes === []) {
            return new ResourceGroupSettings([], [], [], []);
        }

        $holderIds = array_keys($resourceTypes);
        $parentIds = $this->parentIdsWithAncestors($holderIds);

        return new ResourceGroupSettings(
            $resourceTypes,
            $parentIds,
            $this->blockedValues($holderIds),
            $this->policies(array_values(array_unique($resourceTypes)), $actions, array_keys($parentIds)),
        );
    }

    /**
     * リソースグループと、その親から階層の先頭までの祖先の、親(id → 親の id)。止まらなかった循環でも終わるように、たどったものを重ねない(UNION)。
     *
     * @param  list<int>  $resourceGroupIds
     * @return array<int, int|null>
     */
    private function parentIdsWithAncestors(array $resourceGroupIds): array
    {
        return AuthzResourceGroup::fromQuery(<<<'SQL'
            WITH RECURSIVE chain (id) AS (
                SELECT authz_resource_groups.id FROM authz_resource_groups
                WHERE authz_resource_groups.id = ANY (?::bigint[])
                UNION
                SELECT authz_resource_groups.parent_id FROM authz_resource_groups
                JOIN chain ON authz_resource_groups.id = chain.id
                WHERE authz_resource_groups.parent_id IS NOT NULL
            )
            SELECT authz_resource_groups.id, authz_resource_groups.parent_id FROM authz_resource_groups
            JOIN chain ON chain.id = authz_resource_groups.id
            SQL, ['{'.implode(',', $resourceGroupIds).'}'])
            ->mapWithKeys(fn (AuthzResourceGroup $group) => [$group->id => $group->parent_id])
            ->all();
    }

    /**
     * リソースグループ自身の閉塞の値(汎用属性 im_authz-blocked。行のあるものだけ)。
     *
     * @param  list<int>  $resourceGroupIds
     * @return array<int, string>
     */
    private function blockedValues(array $resourceGroupIds): array
    {
        return AuthzResourceGroupAttribute::query()
            ->whereIn('authz_resource_group_id', $resourceGroupIds)
            ->where('key', ResourceGroupAttributeKey::Blocked->value)
            ->get()
            ->mapWithKeys(fn (AuthzResourceGroupAttribute $attribute) => [
                $attribute->authz_resource_group_id => $attribute->value,
            ])
            ->all();
    }

    /**
     * 連鎖の上のリソースグループに置いた、リソースタイプとアクションのポリシー(「リソースタイプ:アクション」ごと)。
     *
     * @param  list<string>  $resourceTypes
     * @param  list<string>  $actions
     * @param  list<int>  $chainIds
     * @return array<string, list<AuthzPolicy>>
     */
    private function policies(array $resourceTypes, array $actions, array $chainIds): array
    {
        return AuthzPolicy::query()
            ->whereIn('resource_type', $resourceTypes)
            ->whereIn('action', $actions)
            ->whereIn('authz_resource_group_id', $chainIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (AuthzPolicy $policy) => "{$policy->resource_type}:{$policy->action}")
            ->map(fn ($policies) => array_values($policies->all()))
            ->all();
    }
}

<?php

namespace Tests\Support;

use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;

/**
 * 初期データのリソースグループを、配下のリソースグループ・リソース・ポリシーごと消す手助け(「初期データの無い状態」を確かめるテスト用)。
 * テストの TX の中で消すので、巻き戻しで戻る。訳・属性は外部キーの cascade で消える。
 */
trait DeletesResourceGroupTree
{
    /**
     * ID が code のリソースグループと配下を、葉の側から消す(親子の外部キーは restrict)。
     */
    protected function deleteResourceGroupTree(string $code): void
    {
        $levels = [[AuthzResourceGroup::query()->where('code', $code)->sole()->id]];

        while (($children = AuthzResourceGroup::query()->whereIn('parent_id', end($levels))->pluck('id')->all()) !== []) {
            $levels[] = $children;
        }

        foreach (array_reverse($levels) as $ids) {
            AuthzPolicy::query()->whereIn('authz_resource_group_id', $ids)->delete();
            AuthzResource::query()->whereIn('authz_resource_group_id', $ids)->delete();
            AuthzResourceGroup::query()->whereIn('id', $ids)->delete();
        }
    }
}

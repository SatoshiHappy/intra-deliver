<?php

namespace App\Queries\Authz;

use App\Models\Authz\AuthzPolicy;

/**
 * 判定するリソースグループの設定(リソースのリソースタイプ・親への連鎖・閉塞の値・ポリシー)と、そこから求める効いている設定と閉塞
 * (docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies「判定の意味」)。
 * {@see ResourceGroupSettingsQuery} が判定するリソースグループとアクションの分をまとめて読み込む(単独の判定も 1 つ分を同じに読み込む)。
 */
final readonly class ResourceGroupSettings
{
    /**
     * @param  array<int, string>  $resourceTypes  リソースグループ → そのリソースのリソースタイプ(リソースを持つものだけ)
     * @param  array<int, int|null>  $parentIds  リソースを持つリソースグループとその祖先 → 親(先頭は null)
     * @param  array<int, string>  $blockedValues  リソースグループ → 閉塞の値(行のあるものだけ)
     * @param  array<string, list<AuthzPolicy>>  $policies  「リソースタイプ:アクション」→ 連鎖の上のリソースグループに置いたポリシー
     */
    public function __construct(
        private array $resourceTypes,
        private array $parentIds,
        private array $blockedValues,
        private array $policies,
    ) {}

    /**
     * リソースグループのリソースのリソースタイプ(リソースを持たない・存在しないリソースグループは null)。
     */
    public function resourceTypeOf(int $resourceGroupId): ?string
    {
        return $this->resourceTypes[$resourceGroupId] ?? null;
    }

    /**
     * 効いている設定: リソースグループから親へたどって、リソースタイプとアクションの組が同じポリシーのうち、対象者ごとに最初に見つかったもの。
     *
     * @return list<AuthzPolicy>
     */
    public function effectivePolicies(int $resourceGroupId, string $resourceType, string $action): array
    {
        $distance = array_flip($this->chainOf($resourceGroupId));

        return array_values(collect($this->policies["{$resourceType}:{$action}"] ?? [])
            ->filter(fn (AuthzPolicy $policy) => isset($distance[$policy->authz_resource_group_id]))
            ->sortBy(fn (AuthzPolicy $policy) => $distance[$policy->authz_resource_group_id])
            ->unique('authz_subject_group_id')
            ->all());
    }

    /**
     * リソースグループ自身の閉塞の値(汎用属性 im_authz-blocked)が ALL か、カンマで区切った要素に「リソースタイプ:アクション」と等しいものがあるか。
     * 行が無ければ閉塞でない。要素は完全一致で比べる(前方一致にしない)。値の書式は閉塞を設定する業務処理が守る。
     */
    public function isBlocked(int $resourceGroupId, string $resourceType, string $action): bool
    {
        $blocked = $this->blockedValues[$resourceGroupId] ?? null;

        if ($blocked === null) {
            return false;
        }

        return $blocked === 'ALL' || in_array("{$resourceType}:{$action}", explode(',', $blocked), true);
    }

    /**
     * リソースグループと、その親から階層の先頭までの祖先(近い順)。読み込んでいないリソースグループなら空。
     * 循環は業務処理が止める(DB では止まらない)。止まらなかった循環でも終わるように、たどったものを重ねない。
     *
     * @return list<int>
     */
    private function chainOf(int $resourceGroupId): array
    {
        $chain = [];
        $current = $resourceGroupId;

        while ($current !== null && array_key_exists($current, $this->parentIds)
            && ! in_array($current, $chain, true)) {
            $chain[] = $current;
            $current = $this->parentIds[$current];
        }

        return $chain;
    }
}

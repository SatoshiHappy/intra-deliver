<?php

namespace App\Queries\Authz;

use App\Enums\Authz\AuthorizationResult;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ResourceGroupAttributeKey;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;

/**
 * 認可の判定(docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies「判定の意味」)。
 */
class AuthorizationQuery
{
    public function __construct(private SubjectQuery $subjects) {}

    /**
     * 判定を求める者が d に、リソースグループのアクションを許されるか(判定の結果が許可のときだけ許す = {@see self::decide()})。
     */
    public function allows(Requester $requester, int $resourceGroupId, string $action, DateTimeInterface $date): bool
    {
        return $this->decide($requester, $resourceGroupId, $action, $date) === AuthorizationResult::Permit;
    }

    /**
     * 判定を求める者が d に、リソース URI のリソースのアクションを許されるか(本家の認可要求 = リソース URI とアクション。
     * docs の 設計/メニュー/認可との結び付け.md 2.3 の読み取りの口)。URI のリソースが無ければ禁止、あればそのリソースグループで {@see self::allows()} と同じ判定。
     */
    public function allowsUri(Requester $requester, string $uri, string $action, DateTimeInterface $date): bool
    {
        $resource = AuthzResource::query()->where('uri', $uri)->first();

        return $resource !== null && $this->allows($requester, $resource->authz_resource_group_id, $action, $date);
    }

    /**
     * 判定を求める者が d に、リソースグループのアクションを行うときの判定の結果(許可・禁止・閉塞)。{@see self::explain()} の結果だけを返す
     * (判定の実装を 1 つにする = docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.2)。
     */
    public function decide(Requester $requester, int $resourceGroupId, string $action, DateTimeInterface $date): AuthorizationResult
    {
        return $this->explain($requester, $resourceGroupId, $action, $date)->result;
    }

    /**
     * 判定の結果と、効いている設定(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.1)。
     * リソースタイプはリソースグループのリソースのもので、リソースを持たない・存在しないリソースグループは、見るポリシーが無いので禁止(設定は空)。
     * 効いている設定 = リソースグループから親へたどって、同じリソースタイプとアクションのポリシーのうち、対象者ごとに最初に見つかったもの
     * (matches = 判定を求める者が d にその対象者に合う。{@see SubjectGroupMatcher})。
     * 1. 閉塞: リソースグループ自身の閉塞の値が ALL か、リソースタイプとアクションの組と等しい要素を含むなら閉塞(ポリシーによらない。親の閉塞はたどらない)。
     *    閉塞でも効いている設定は同じに作る。
     * 2. 閉塞でなければ、効いている設定のうち合うもので 1 つでも許可なら許可、それ以外は禁止。どこにも設定が無ければ禁止。
     */
    public function explain(Requester $requester, int $resourceGroupId, string $action, DateTimeInterface $date): AuthorizationExplanation
    {
        $resource = AuthzResource::query()->where('authz_resource_group_id', $resourceGroupId)->first();

        if ($resource === null) {
            return new AuthorizationExplanation(AuthorizationResult::Deny, []);
        }

        $effective = $this->effectivePolicies($this->resourceGroupChain($resourceGroupId), $resource->resource_type, $action);
        $matching = $this->subjects->matchingSubjectGroupIds($requester, array_map(fn (AuthzPolicy $policy) => $policy->authz_subject_group_id, $effective), $date);
        $policies = array_map(fn (AuthzPolicy $policy) => new EffectivePolicy(
            policyId: $policy->id,
            subjectGroupId: $policy->authz_subject_group_id,
            resourceGroupId: $policy->authz_resource_group_id,
            effect: $policy->effect,
            matches: in_array($policy->authz_subject_group_id, $matching, true),
        ), $effective);

        return new AuthorizationExplanation($this->resultOf($resourceGroupId, $resource->resource_type, $action, $policies), $policies);
    }

    /**
     * 閉塞なら閉塞、そうでなければ合う設定に許可が 1 つでもあれば許可、無ければ禁止。
     *
     * @param  list<EffectivePolicy>  $policies
     */
    private function resultOf(int $resourceGroupId, string $resourceType, string $action, array $policies): AuthorizationResult
    {
        if ($this->isBlocked($resourceGroupId, $resourceType, $action)) {
            return AuthorizationResult::Block;
        }

        foreach ($policies as $policy) {
            if ($policy->matches && $policy->effect === PolicyEffect::Permit) {
                return AuthorizationResult::Permit;
            }
        }

        return AuthorizationResult::Deny;
    }

    /**
     * リソースグループ自身の閉塞の値(汎用属性 im_authz-blocked)が ALL か、カンマで区切った要素に「リソースタイプ:アクション」と等しいものがあるか。
     * 行が無ければ閉塞でない。要素は完全一致で比べる(前方一致にしない)。値の書式は閉塞を設定する業務処理が守る。
     */
    private function isBlocked(int $resourceGroupId, string $resourceType, string $action): bool
    {
        $blocked = AuthzResourceGroupAttribute::query()
            ->where('authz_resource_group_id', $resourceGroupId)
            ->where('key', ResourceGroupAttributeKey::Blocked->value)
            ->first();

        if ($blocked === null) {
            return false;
        }

        return $blocked->value === 'ALL' || in_array("{$resourceType}:{$action}", explode(',', $blocked->value), true);
    }

    /**
     * 効いている設定: リソースグループから親へたどって、リソースタイプとアクションの組が同じポリシーのうち、対象者ごとに最初に見つかったもの。
     *
     * @param  list<int>  $chain  リソースグループとその祖先(近い順)
     * @return list<AuthzPolicy>
     */
    private function effectivePolicies(array $chain, string $resourceType, string $action): array
    {
        $distance = array_flip($chain);

        return array_values(AuthzPolicy::query()
            ->where('resource_type', $resourceType)
            ->where('action', $action)
            ->whereIn('authz_resource_group_id', $chain)
            ->get()
            ->sortBy(fn (AuthzPolicy $policy) => $distance[$policy->authz_resource_group_id])
            ->unique('authz_subject_group_id')
            ->all());
    }

    /**
     * リソースグループと、その親から階層の先頭までの祖先(近い順)。リソースグループが無ければ空。
     * 循環は業務処理が止める(DB では止まらない)。止まらなかった循環でも終わるように、たどったものを重ねない。
     *
     * @return list<int>
     */
    private function resourceGroupChain(int $resourceGroupId): array
    {
        $groups = AuthzResourceGroup::fromQuery(<<<'SQL'
            WITH RECURSIVE chain (id) AS (
                SELECT authz_resource_groups.id FROM authz_resource_groups WHERE authz_resource_groups.id = ?
                UNION
                SELECT authz_resource_groups.parent_id FROM authz_resource_groups JOIN chain ON authz_resource_groups.id = chain.id
                WHERE authz_resource_groups.parent_id IS NOT NULL
            )
            SELECT authz_resource_groups.* FROM authz_resource_groups JOIN chain ON chain.id = authz_resource_groups.id
            SQL, [$resourceGroupId])->keyBy('id');

        $chain = [];
        $current = $groups->get($resourceGroupId);

        while ($current !== null && ! in_array($current->id, $chain, true)) {
            $chain[] = $current->id;
            $current = $current->parent_id === null ? null : $groups->get($current->parent_id);
        }

        return $chain;
    }
}

<?php

namespace App\Queries\Authz;

use App\Enums\Authz\AuthorizationResult;
use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;

/**
 * 認可の判定(docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies「判定の意味」)。
 */
class AuthorizationQuery
{
    public function __construct(
        private SubjectQuery $subjects,
        private ResourceGroupSettingsQuery $settings,
    ) {}

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
     * 判定の結果と、効いている設定(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.1)。{@see self::explainEach()} を 1 組で呼んだ結果。
     */
    public function explain(Requester $requester, int $resourceGroupId, string $action, DateTimeInterface $date): AuthorizationExplanation
    {
        return $this->explainEach($requester, [$resourceGroupId], [$action], $date)[$resourceGroupId][$action];
    }

    /**
     * リソースグループ × アクションの組ごとの判定の結果と、効いている設定(リソースグループ id → アクション → 結果)。
     * 設定({@see ResourceGroupSettingsQuery})と対象者に合うか({@see SubjectQuery::matchingSubjectGroupIds()})は、組の数によらず 1 回ずつ読む
     * (1 回の表示で多くの組を判定する画面のため。単独の {@see self::explain()} もここを通る = 判定の実装を 1 つにする)。
     * リソースタイプはリソースグループのリソースのもので、リソースを持たない・存在しないリソースグループは、見るポリシーが無いので禁止(設定は空)。
     * 効いている設定 = {@see ResourceGroupSettings::effectivePolicies()}
     * (matches = 判定を求める者が d にその対象者に合う。{@see SubjectGroupMatcher})。
     * 1. 閉塞: {@see ResourceGroupSettings::isBlocked()} なら閉塞(ポリシーによらない。親の閉塞はたどらない)。閉塞でも効いている設定は同じに作る。
     * 2. 閉塞でなければ、効いている設定のうち合うもので 1 つでも許可なら許可、それ以外は禁止。どこにも設定が無ければ禁止。
     *
     * @param  list<int>  $resourceGroupIds
     * @param  list<string>  $actions
     * @return array<int, array<string, AuthorizationExplanation>>
     */
    public function explainEach(
        Requester $requester,
        array $resourceGroupIds,
        array $actions,
        DateTimeInterface $date,
    ): array {
        $settings = $this->settings->settingsOf($resourceGroupIds, $actions);
        $effective = $this->effectivePoliciesOf($settings, $resourceGroupIds, $actions);
        $matching = $this->subjects->matchingSubjectGroupIds($requester, $this->subjectGroupIdsOf($effective), $date);
        $explanations = [];

        foreach ($effective as $resourceGroupId => $byAction) {
            foreach ($byAction as $action => $policies) {
                $explanations[$resourceGroupId][$action] = $this->explanationOf(
                    $settings,
                    $resourceGroupId,
                    $action,
                    $policies,
                    $matching,
                );
            }
        }

        return $explanations;
    }

    /**
     * 組ごとの効いている設定(リソースを持たないリソースグループは空)。
     *
     * @param  list<int>  $resourceGroupIds
     * @param  list<string>  $actions
     * @return array<int, array<string, list<AuthzPolicy>>>
     */
    private function effectivePoliciesOf(
        ResourceGroupSettings $settings,
        array $resourceGroupIds,
        array $actions,
    ): array {
        $effective = [];

        foreach ($resourceGroupIds as $resourceGroupId) {
            $resourceType = $settings->resourceTypeOf($resourceGroupId);

            foreach ($actions as $action) {
                $effective[$resourceGroupId][$action] = $resourceType === null
                    ? []
                    : $settings->effectivePolicies($resourceGroupId, $resourceType, $action);
            }
        }

        return $effective;
    }

    /**
     * 効いている設定の対象者(重ねない)。
     *
     * @param  array<int, array<string, list<AuthzPolicy>>>  $effective
     * @return list<int>
     */
    private function subjectGroupIdsOf(array $effective): array
    {
        $subjectGroupIds = [];

        foreach ($effective as $byAction) {
            foreach ($byAction as $policies) {
                foreach ($policies as $policy) {
                    $subjectGroupIds[$policy->authz_subject_group_id] = true;
                }
            }
        }

        return array_keys($subjectGroupIds);
    }

    /**
     * 1 組の判定の結果と効いている設定。
     *
     * @param  list<AuthzPolicy>  $effective
     * @param  list<int>  $matching  判定を求める者が d に合う対象者
     */
    private function explanationOf(
        ResourceGroupSettings $settings,
        int $resourceGroupId,
        string $action,
        array $effective,
        array $matching,
    ): AuthorizationExplanation {
        $resourceType = $settings->resourceTypeOf($resourceGroupId);

        if ($resourceType === null) {
            return new AuthorizationExplanation(AuthorizationResult::Deny, []);
        }

        $policies = array_map(fn (AuthzPolicy $policy) => new EffectivePolicy(
            policyId: $policy->id,
            subjectGroupId: $policy->authz_subject_group_id,
            resourceGroupId: $policy->authz_resource_group_id,
            effect: $policy->effect,
            matches: in_array($policy->authz_subject_group_id, $matching, true),
        ), $effective);
        $isBlocked = $settings->isBlocked($resourceGroupId, $resourceType, $action);

        return new AuthorizationExplanation($this->resultOf($isBlocked, $policies), $policies);
    }

    /**
     * 閉塞なら閉塞、そうでなければ合う設定に許可が 1 つでもあれば許可、無ければ禁止。
     *
     * @param  list<EffectivePolicy>  $policies
     */
    private function resultOf(bool $isBlocked, array $policies): AuthorizationResult
    {
        if ($isBlocked) {
            return AuthorizationResult::Block;
        }

        foreach ($policies as $policy) {
            if ($policy->matches && $policy->effect === PolicyEffect::Permit) {
                return AuthorizationResult::Permit;
            }
        }

        return AuthorizationResult::Deny;
    }
}

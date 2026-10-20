<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\AuthorizationResult;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationExplanation;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\EffectivePolicy;
use App\Queries\Authz\SubjectQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 判定の結果と効いている設定(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.1・3.2): explain の結果は各テストに直に書く(decide は explain の結果を返すだけなので、両者の一致は確かめない)。効いている設定 =
 * リソースグループから親へたどって、対象者ごとに最初に見つかったポリシー(置き場・合うか)。効いたポリシー = 許可なら合う許可のすべて、
 * 禁止なら合う禁止のすべて(空なら既定の禁止)、閉塞なら空。閉塞でも設定は同じに作る。リソースの無いリソースグループは禁止で設定は空。
 * SubjectQuery::matchingSubjectGroupIds = 渡した対象者のうち合うもの(空の入力で空)。
 *
 * リソースグループの階層 = 先頭 top > leaf(どちらもメニューグループのリソースを持つ)。対象者 = 利用者に合う mine・alsoMine と、合わない others。
 */
final class AuthorizationQueryExplainTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private User $user;

    private AuthzResourceGroup $top;

    private AuthzResourceGroup $leaf;

    private AuthzSubjectGroup $mine;

    private AuthzSubjectGroup $alsoMine;

    private AuthzSubjectGroup $others;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->top = $this->resourceGroup(null);
        $this->leaf = $this->resourceGroup($this->top);
        $this->mine = $this->subjectGroupFor($this->user);
        $this->alsoMine = $this->subjectGroupFor($this->user);
        $this->others = $this->subjectGroupFor($this->user());
    }

    public function test_an_inherited_permit_decides_with_its_place(): void
    {
        $permit = $this->policy($this->top, $this->mine, PolicyEffect::Permit);
        $othersPermit = $this->policy($this->leaf, $this->others, PolicyEffect::Permit);

        $explanation = $this->explain($this->leaf->id);

        $this->assertSame(AuthorizationResult::Permit, $explanation->result);
        $this->assertEqualsCanonicalizing([
            [$permit->id, $this->mine->id, $this->top->id, 'permit', true],
            [$othersPermit->id, $this->others->id, $this->leaf->id, 'permit', false],
        ], $this->summaries($explanation->policies));
        $this->assertSame([$permit->id], $this->policyIds($explanation->decidingPolicies()));
    }

    public function test_a_deny_on_the_child_overrides_the_permit_of_the_parent_for_the_subject(): void
    {
        $this->policy($this->top, $this->mine, PolicyEffect::Permit);
        $deny = $this->policy($this->leaf, $this->mine, PolicyEffect::Deny);
        $alsoDeny = $this->policy($this->top, $this->alsoMine, PolicyEffect::Deny);

        $explanation = $this->explain($this->leaf->id);

        $this->assertSame(AuthorizationResult::Deny, $explanation->result);
        $this->assertEqualsCanonicalizing([
            [$deny->id, $this->mine->id, $this->leaf->id, 'deny', true],
            [$alsoDeny->id, $this->alsoMine->id, $this->top->id, 'deny', true],
        ], $this->summaries($explanation->policies));
        $this->assertEqualsCanonicalizing([$deny->id, $alsoDeny->id], $this->policyIds($explanation->decidingPolicies()));
    }

    public function test_all_matching_permits_decide(): void
    {
        $permit = $this->policy($this->leaf, $this->mine, PolicyEffect::Permit);
        $alsoPermit = $this->policy($this->top, $this->alsoMine, PolicyEffect::Permit);

        $this->assertEqualsCanonicalizing([$permit->id, $alsoPermit->id], $this->policyIds($this->explain($this->leaf->id)->decidingPolicies()));
    }

    public function test_a_deny_without_a_matching_subject_has_no_deciding_policy(): void
    {
        $this->policy($this->leaf, $this->others, PolicyEffect::Permit);

        $explanation = $this->explain($this->leaf->id);

        $this->assertSame(AuthorizationResult::Deny, $explanation->result);
        $this->assertCount(1, $explanation->policies);
        $this->assertSame([], $explanation->decidingPolicies());
    }

    public function test_a_blocked_group_still_has_its_policies_but_none_decides(): void
    {
        $permit = $this->policy($this->leaf, $this->mine, PolicyEffect::Permit);
        AuthzResourceGroupAttribute::factory()->blocked()->create(['authz_resource_group_id' => $this->leaf->id]);

        $explanation = $this->explain($this->leaf->id);

        $this->assertSame(AuthorizationResult::Block, $explanation->result);
        $this->assertSame([[$permit->id, $this->mine->id, $this->leaf->id, 'permit', true]], $this->summaries($explanation->policies));
        $this->assertSame([], $explanation->decidingPolicies());
    }

    public function test_a_group_without_a_resource_is_denied_with_no_policies(): void
    {
        $withoutResource = AuthzResourceGroup::factory()->create(['parent_id' => $this->top->id]);
        $this->policy($withoutResource, $this->mine, PolicyEffect::Permit);

        $explanation = $this->explain($withoutResource->id);

        $this->assertSame(AuthorizationResult::Deny, $explanation->result);
        $this->assertSame([], $explanation->policies);
        $this->assertSame(AuthorizationResult::Deny, $this->explain($this->leaf->id + 1000)->result);
    }

    public function test_matching_subject_group_ids_are_the_matching_ones_only(): void
    {
        $subjects = app(SubjectQuery::class);
        $requester = new Requester($this->user->id, null);

        $this->assertSame([$this->mine->id, $this->alsoMine->id], $subjects->matchingSubjectGroupIds($requester, [$this->mine->id, $this->others->id, $this->alsoMine->id], self::day(self::DATE)));
        $this->assertSame([], $subjects->matchingSubjectGroupIds($requester, [], self::day(self::DATE)));
    }

    /**
     * リソースを持つリソースグループ。
     */
    private function resourceGroup(?AuthzResourceGroup $parent): AuthzResourceGroup
    {
        $group = AuthzResourceGroup::factory()->create(['parent_id' => $parent?->id]);
        AuthzResource::factory()->create(['authz_resource_group_id' => $group->id, 'resource_type' => 'im-menu-group']);

        return $group;
    }

    private function subjectGroupFor(User $user): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => $user->id]);

        return $group;
    }

    private function policy(AuthzResourceGroup $resourceGroup, AuthzSubjectGroup $subjectGroup, PolicyEffect $effect): AuthzPolicy
    {
        return AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $resourceGroup->id,
            'authz_subject_group_id' => $subjectGroup->id,
            'resource_type' => 'im-menu-group',
            'action' => 'read',
            'effect' => $effect,
        ]);
    }

    private function explain(int $resourceGroupId): AuthorizationExplanation
    {
        return app(AuthorizationQuery::class)->explain(new Requester($this->user->id, null), $resourceGroupId, 'read', self::day(self::DATE));
    }

    /**
     * @param  list<EffectivePolicy>  $policies
     * @return list<array{int, int, int, string, bool}>
     */
    private function summaries(array $policies): array
    {
        return array_map(fn (EffectivePolicy $policy) => [$policy->policyId, $policy->subjectGroupId, $policy->resourceGroupId, $policy->effect->value, $policy->matches], $policies);
    }

    /**
     * @param  list<EffectivePolicy>  $policies
     * @return list<int>
     */
    private function policyIds(array $policies): array
    {
        return array_map(fn (EffectivePolicy $policy) => $policy->policyId, $policies);
    }
}

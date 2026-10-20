<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の判定(docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies の「判定の意味」: リソースタイプはリソースグループのリソースのもの。
 * 合う対象者ごとに、リソースグループから親へたどって、同じリソースタイプとアクションのポリシーで最初に見つかったエフェクトをその対象者の値とし、
 * 1 つでも許可なら許可、それ以外は禁止。どこにも設定が無ければ禁止。リソースを持たない・存在しないリソースグループは禁止)。
 *
 * リソースグループの階層 = 先頭 top > middle > leaf(どれもメニューグループ im-menu-group のリソースを持つ)。
 * 対象者 = 利用者に合う mine・alsoMine と、合わない others。
 */
final class AuthorizationQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private User $user;

    private AuthzResourceGroup $top;

    private AuthzResourceGroup $middle;

    private AuthzResourceGroup $leaf;

    private AuthzSubjectGroup $mine;

    private AuthzSubjectGroup $alsoMine;

    private AuthzSubjectGroup $others;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user([['2020-01-01', '2025-04-01']]);
        $this->top = $this->resourceGroup(null);
        $this->middle = $this->resourceGroup($this->top);
        $this->leaf = $this->resourceGroup($this->middle);
        $this->mine = $this->subjectGroupFor($this->user);
        $this->alsoMine = $this->subjectGroupFor($this->user);
        $this->others = $this->subjectGroupFor($this->user());
    }

    public function test_denies_without_any_policy(): void
    {
        $this->assertFalse($this->allows($this->leaf));
    }

    public function test_permits_with_a_permit_for_a_matching_subject(): void
    {
        $this->policy($this->leaf, $this->mine, PolicyEffect::Permit);

        $this->assertTrue($this->allows($this->leaf));
        $this->assertFalse($this->allows($this->middle), '親は子の設定を継承しない');
    }

    public function test_an_unset_group_inherits_from_its_parents(): void
    {
        $this->policy($this->top, $this->mine, PolicyEffect::Permit);

        $this->assertTrue($this->allows($this->leaf));
    }

    public function test_the_nearest_effect_wins_for_a_subject(): void
    {
        $this->policy($this->top, $this->mine, PolicyEffect::Permit);
        $this->policy($this->middle, $this->mine, PolicyEffect::Deny);

        $this->assertFalse($this->allows($this->leaf));
        $this->assertTrue($this->allows($this->top));
    }

    public function test_one_permitting_subject_is_enough(): void
    {
        $this->policy($this->leaf, $this->mine, PolicyEffect::Deny);
        $this->policy($this->top, $this->alsoMine, PolicyEffect::Permit);

        $this->assertTrue($this->allows($this->leaf));
    }

    public function test_a_permit_for_a_subject_that_does_not_match_does_not_count(): void
    {
        $this->policy($this->leaf, $this->others, PolicyEffect::Permit);

        $this->assertFalse($this->allows($this->leaf));
    }

    public function test_a_permit_for_another_action_does_not_count(): void
    {
        $this->policy($this->leaf, $this->mine, PolicyEffect::Permit, 'admin');

        $this->assertFalse($this->allows($this->leaf));
        $this->assertTrue($this->allows($this->leaf, 'admin'));
    }

    public function test_the_subject_is_matched_on_the_date(): void
    {
        $this->policy($this->leaf, $this->mine, PolicyEffect::Permit);

        $this->assertTrue($this->allows($this->leaf, date: '2025-03-31'));
        $this->assertFalse($this->allows($this->leaf, date: '2025-04-01'));
    }

    public function test_denies_for_a_resource_group_that_does_not_exist(): void
    {
        $this->policy($this->top, $this->mine, PolicyEffect::Permit);

        $this->assertFalse(app(AuthorizationQuery::class)->allows(new Requester($this->user->id, null), $this->leaf->id + 1000, 'read', self::day(self::DATE)));
    }

    public function test_denies_for_a_resource_group_without_a_resource_even_with_permits_on_it_and_its_parent(): void
    {
        $withoutResource = AuthzResourceGroup::factory()->create(['parent_id' => $this->top->id]);
        $this->policy($withoutResource, $this->mine, PolicyEffect::Permit);
        $this->policy($this->top, $this->mine, PolicyEffect::Permit);

        $this->assertFalse($this->allows($withoutResource));
    }

    public function test_a_deny_of_another_resource_type_with_the_same_action_is_not_inherited(): void
    {
        $service = $this->resourceGroup($this->middle, 'service');
        $this->policy($this->middle, $this->mine, PolicyEffect::Deny, 'execute', 'im-menu-group');
        $this->policy($this->top, $this->mine, PolicyEffect::Permit, 'execute', 'service');

        $this->assertTrue($this->allows($service, 'execute'));
    }

    public function test_a_permit_of_another_resource_type_with_the_same_action_is_not_inherited(): void
    {
        $service = $this->resourceGroup($this->middle, 'service');
        $this->policy($this->middle, $this->mine, PolicyEffect::Permit, 'execute', 'im-menu-group');

        $this->assertFalse($this->allows($service, 'execute'));
    }

    public function test_a_policy_on_a_group_without_a_resource_applies_only_to_resources_of_its_type(): void
    {
        $category = AuthzResourceGroup::factory()->create();
        $menuGroup = $this->resourceGroup($category);
        $service = $this->resourceGroup($category, 'service');
        $this->policy($category, $this->mine, PolicyEffect::Permit, 'read', 'im-menu-group');

        $this->assertTrue($this->allows($menuGroup));
        $this->assertFalse($this->allows($service));
    }

    public function test_permits_a_requester_without_a_user_through_an_anonymous_subject(): void
    {
        $anonymous = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->forMetaSubject(MetaSubject::Anonymous)->create(['authz_subject_group_id' => $anonymous->id]);
        $this->policy($this->leaf, $anonymous, PolicyEffect::Permit);

        $allowed = app(AuthorizationQuery::class)->allows(new Requester(null, null), $this->leaf->id, 'read', self::day(self::DATE));

        $this->assertTrue($allowed);
        $this->assertFalse($this->allows($this->leaf));
    }

    public function test_a_cycle_of_resource_groups_left_by_mistake_does_not_loop_forever(): void
    {
        $this->top->update(['parent_id' => $this->leaf->id]);
        $this->policy($this->middle, $this->mine, PolicyEffect::Permit);

        $this->assertTrue($this->allows($this->leaf));
    }

    public function test_a_two_group_cycle_ends_and_takes_the_first_effect_along_the_way_up(): void
    {
        $this->middle->update(['parent_id' => $this->leaf->id]);
        $this->policy($this->leaf, $this->mine, PolicyEffect::Deny);
        $this->policy($this->middle, $this->mine, PolicyEffect::Permit);
        $this->policy($this->top, $this->mine, PolicyEffect::Permit);

        $this->assertFalse($this->allows($this->leaf));
        $this->assertTrue($this->allows($this->middle));
    }

    public function test_allows_by_uri_with_the_decision_of_the_resource_group_of_the_uri(): void
    {
        $this->policy($this->middle, $this->mine, PolicyEffect::Permit);

        $this->assertTrue($this->allowsUri($this->uriOf($this->leaf)));
        $this->assertFalse($this->allowsUri($this->uriOf($this->top)));
    }

    public function test_denies_by_uri_for_a_uri_without_a_resource_even_if_it_is_a_prefix_of_one(): void
    {
        $this->policy($this->top, $this->mine, PolicyEffect::Permit);
        $uri = $this->uriOf($this->leaf);

        $this->assertFalse($this->allowsUri(substr($uri, 0, -1)));
        $this->assertFalse($this->allowsUri("{$uri}/"));
    }

    /**
     * リソースを持つリソースグループ(リソースのタイプは resourceType、URI は factory が作る)。
     */
    private function resourceGroup(?AuthzResourceGroup $parent, string $resourceType = 'im-menu-group'): AuthzResourceGroup
    {
        $group = AuthzResourceGroup::factory()->create(['parent_id' => $parent?->id]);
        AuthzResource::factory()->create(['authz_resource_group_id' => $group->id, 'resource_type' => $resourceType]);

        return $group;
    }

    private function subjectGroupFor(User $user): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => $user->id]);

        return $group;
    }

    private function policy(
        AuthzResourceGroup $resourceGroup,
        AuthzSubjectGroup $subjectGroup,
        PolicyEffect $effect,
        string $action = 'read',
        string $resourceType = 'im-menu-group',
    ): void {
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $resourceGroup->id,
            'authz_subject_group_id' => $subjectGroup->id,
            'resource_type' => $resourceType,
            'action' => $action,
            'effect' => $effect,
        ]);
    }

    private function allows(AuthzResourceGroup $resourceGroup, string $action = 'read', string $date = self::DATE): bool
    {
        return app(AuthorizationQuery::class)->allows(new Requester($this->user->id, null), $resourceGroup->id, $action, self::day($date));
    }

    private function allowsUri(string $uri): bool
    {
        return app(AuthorizationQuery::class)->allowsUri(new Requester($this->user->id, null), $uri, 'read', self::day(self::DATE));
    }

    private function uriOf(AuthzResourceGroup $resourceGroup): string
    {
        return $resourceGroup->authzResource()->firstOrFail()->uri;
    }
}

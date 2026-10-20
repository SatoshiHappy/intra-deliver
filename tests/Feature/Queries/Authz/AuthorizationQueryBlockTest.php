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
use App\Queries\Authz\AuthorizationQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の判定の閉塞(docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies の「判定の意味」1: リソースグループ自身の閉塞の値が ALL か、
 * リソースタイプとアクションの組と等しい要素を含むなら閉塞。ポリシーによらず、親の閉塞はたどらない。リソースが無ければ閉塞より先に禁止)。
 *
 * リソースグループ = 親 parent > 子 child(どちらもメニューグループ im-menu-group のリソースを持つ)。利用者は親に許可のポリシーを持つ。
 */
final class AuthorizationQueryBlockTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private User $user;

    private AuthzResourceGroup $parent;

    private AuthzResourceGroup $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->parent = $this->resourceGroup(null);
        $this->child = $this->resourceGroup($this->parent);
        $subjectGroup = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $subjectGroup->id, 'user_id' => $this->user->id]);

        foreach (['read', 'admin'] as $action) {
            AuthzPolicy::factory()->create([
                'authz_resource_group_id' => $this->parent->id,
                'authz_subject_group_id' => $subjectGroup->id,
                'resource_type' => 'im-menu-group',
                'action' => $action,
                'effect' => PolicyEffect::Permit,
            ]);
        }
    }

    /**
     * 閉塞の値 → im-menu-group の read が閉塞か。
     *
     * @return array<string, array{string, bool}>
     */
    public static function blockedValues(): array
    {
        return [
            'ALL' => ['ALL', true],
            '組が一致' => ['im-menu-group:read', true],
            '組の 1 つが一致' => ['im-menu-group:admin,im-menu-group:read', true],
            '別のアクション' => ['im-menu-group:admin', false],
            '別のリソースタイプ' => ['service:read', false],
            '前方一致にならない' => ['im-menu-group:readx', false],
            '小文字の all は ALL でない' => ['all', false],
        ];
    }

    #[DataProvider('blockedValues')]
    public function test_blocks_by_the_value_of_the_group_itself_regardless_of_permits(string $value, bool $isBlocked): void
    {
        AuthzResourceGroupAttribute::factory()->blocked($value)->create(['authz_resource_group_id' => $this->child->id]);

        $this->assertSame($isBlocked ? AuthorizationResult::Block : AuthorizationResult::Permit, $this->decide($this->child));
        $this->assertSame(! $isBlocked, $this->allows($this->child));
    }

    public function test_permits_without_a_blocked_attribute(): void
    {
        $this->assertSame(AuthorizationResult::Permit, $this->decide($this->child));
    }

    public function test_a_blocked_parent_does_not_block_a_child_without_its_own_value(): void
    {
        AuthzResourceGroupAttribute::factory()->blocked()->create(['authz_resource_group_id' => $this->parent->id]);

        $this->assertSame(AuthorizationResult::Block, $this->decide($this->parent));
        $this->assertSame(AuthorizationResult::Permit, $this->decide($this->child));
    }

    public function test_an_attribute_with_another_key_does_not_block(): void
    {
        AuthzResourceGroupAttribute::factory()->create(['authz_resource_group_id' => $this->child->id, 'key' => 'im_other-module', 'value' => 'ALL']);

        $this->assertSame(AuthorizationResult::Permit, $this->decide($this->child));
    }

    public function test_a_group_without_a_resource_is_denied_before_its_blocked_value_is_read(): void
    {
        $withoutResource = AuthzResourceGroup::factory()->create(['parent_id' => $this->parent->id]);
        AuthzResourceGroupAttribute::factory()->blocked()->create(['authz_resource_group_id' => $withoutResource->id]);

        $this->assertSame(AuthorizationResult::Deny, $this->decide($withoutResource));
    }

    public function test_denies_without_a_matching_permit_when_not_blocked(): void
    {
        AuthzResourceGroupAttribute::factory()->blocked('im-menu-group:admin')->create(['authz_resource_group_id' => $this->child->id]);

        $result = app(AuthorizationQuery::class)->decide(new Requester($this->user()->id, null), $this->child->id, 'read', self::day(self::DATE));

        $this->assertSame(AuthorizationResult::Deny, $result);
    }

    /**
     * メニューグループのリソースを持つリソースグループ。
     */
    private function resourceGroup(?AuthzResourceGroup $parent): AuthzResourceGroup
    {
        $group = AuthzResourceGroup::factory()->create(['parent_id' => $parent?->id]);
        AuthzResource::factory()->create(['authz_resource_group_id' => $group->id, 'resource_type' => 'im-menu-group']);

        return $group;
    }

    private function decide(AuthzResourceGroup $resourceGroup): AuthorizationResult
    {
        return app(AuthorizationQuery::class)->decide(new Requester($this->user->id, null), $resourceGroup->id, 'read', self::day(self::DATE));
    }

    private function allows(AuthzResourceGroup $resourceGroup): bool
    {
        return app(AuthorizationQuery::class)->allows(new Requester($this->user->id, null), $resourceGroup->id, 'read', self::day(self::DATE));
    }
}

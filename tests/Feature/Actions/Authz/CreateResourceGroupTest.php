<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateResourceGroup;
use App\Enums\Authz\ResourceGroupAttributeKey;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースグループを作る(docs の 設計/メニュー/認可との結び付け.md 2.4、設計/共通マスタ/ロールと認可/リソース.md の閉塞の項: 親があれば親の閉塞を写す)。
 */
final class CreateResourceGroupTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_creates_a_resource_group_with_display_names_and_descriptions_per_locale(): void
    {
        $parent = AuthzResourceGroup::factory()->create();

        $group = $this->create('example.menu', $parent->id, [
            'ja' => ['display_name' => 'メニュー', 'description' => 'メニューの画面'],
            'en' => ['display_name' => 'Menu'],
        ], 3);

        $this->assertSame(['example.menu', $parent->id, 3], [$group->code, $group->parent_id, $group->sort_key]);
        $this->assertEqualsCanonicalizing(
            [['メニュー', 'メニューの画面'], ['Menu', null]],
            $group->authzResourceGroupTranslations()->get()->map(fn (AuthzResourceGroupTranslation $translation) => [$translation->display_name, $translation->description])->all(),
        );
        $this->assertNull($group->authzResource()->first());
    }

    public function test_accepts_an_id_of_255_characters_even_in_multibyte_characters(): void
    {
        $group = $this->create(str_repeat('あ', 255));

        $this->assertModelExists($group);
    }

    public function test_copies_the_blocked_value_of_the_parent(): void
    {
        $blocked = $this->groupBlocked('ALL');
        $blockedByAction = $this->groupBlocked('service:execute,im-menu-group:read');

        $this->assertSame('ALL', $this->blockedValueOf($this->create('a', $blocked->id)));
        $this->assertSame('service:execute,im-menu-group:read', $this->blockedValueOf($this->create('b', $blockedByAction->id)));
    }

    public function test_does_not_block_a_group_under_an_unblocked_parent_or_at_the_top(): void
    {
        $parent = AuthzResourceGroup::factory()->create();

        $this->assertNull($this->blockedValueOf($this->create('a', $parent->id)));
        $this->assertNull($this->blockedValueOf($this->create('b')));
    }

    public function test_rejects_a_display_name_in_an_unknown_locale(): void
    {
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('locale', fn () => $this->create('a', null, ['xx' => ['display_name' => 'a']]));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function create(string $code, ?int $parentId = null, array $translations = [], int $sortKey = 0): AuthzResourceGroup
    {
        return app(CreateResourceGroup::class)->execute($code, $parentId, $translations, $sortKey);
    }

    private function groupBlocked(string $value): AuthzResourceGroup
    {
        $group = AuthzResourceGroup::factory()->create();
        AuthzResourceGroupAttribute::factory()->create(['authz_resource_group_id' => $group->id, 'key' => ResourceGroupAttributeKey::Blocked->value, 'value' => $value]);

        return $group;
    }

    private function blockedValueOf(AuthzResourceGroup $group): ?string
    {
        return $group->authzResourceGroupAttributes()->where('key', ResourceGroupAttributeKey::Blocked->value)->value('value');
    }
}

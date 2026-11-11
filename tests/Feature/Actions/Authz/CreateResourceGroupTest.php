<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateResourceGroup;
use App\Enums\Authz\ResourceGroupAttributeKey;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースグループを作る(docs の 設計/メニュー/認可との結び付け/業務処理と長さ.md 2.4、設計/共通マスタ/ロールと認可/リソース.md の閉塞の項: 親があれば親の閉塞を写す。
 * 同じ文書の「並び順の入れ方」: 渡されなければ兄弟の最大 + 1)。
 */
final class CreateResourceGroupTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use AssertsRejectedByRule;

    /** 並び順の検査制約の上限(docs の 設計/共通マスタ/共通の規則.md 4 節) */
    private const MAX_SORT_KEY = 999_999_999_999_999;

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

    public function test_puts_zero_without_siblings_and_the_maximum_of_the_siblings_plus_one_without_a_sort_key(): void
    {
        $empty = AuthzResourceGroup::factory()->create();
        $zero = $this->parentWithChildren([0]);
        $gap = $this->parentWithChildren([0, 5]);

        $this->assertSame(0, $this->create('a', $empty->id)->sort_key);
        $this->assertSame(1, $this->create('b', $zero->id)->sort_key);
        $this->assertSame(6, $this->create('c', $gap->id)->sort_key);
        $this->assertSame(7, $this->create('d', $gap->id)->sort_key, '続けて作ると、作った順に並ぶ');
    }

    public function test_counts_only_the_siblings_under_the_same_parent(): void
    {
        $parent = $this->parentWithChildren([0]);
        $this->parentWithChildren([99]);

        $this->assertSame(1, $this->create('a', $parent->id)->sort_key);
    }

    public function test_puts_the_maximum_of_the_groups_without_a_parent_plus_one_at_the_top(): void
    {
        $top = (int) AuthzResourceGroup::query()->whereNull('parent_id')->max('sort_key') + 10;
        AuthzResourceGroup::factory()->create(['sort_key' => $top]);
        $this->parentWithChildren([$top + 50]);

        $this->assertSame($top + 1, $this->create('a')->sort_key, '親の下の行の値は数えない');
    }

    public function test_puts_a_given_sort_key_as_it_is(): void
    {
        $parent = $this->parentWithChildren([5]);

        $this->assertSame(2, $this->create('a', $parent->id, [], 2)->sort_key);
        $this->assertSame(5, $this->create('b', $parent->id, [], 5)->sort_key);
    }

    public function test_the_check_constraint_stops_the_maximum_plus_one_over_the_range_without_writing(): void
    {
        $parent = $this->parentWithChildren([self::MAX_SORT_KEY]);
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejectedBy('authz_resource_groups_sort_key_check', fn () => $this->create('a', $parent->id));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
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
    private function create(string $code, ?int $parentId = null, array $translations = [], ?int $sortKey = null): AuthzResourceGroup
    {
        return app(CreateResourceGroup::class)->execute($code, $parentId, $translations, $sortKey);
    }

    /**
     * 親のリソースグループと、その下の並び順 $sortKeys の子。
     *
     * @param  list<int>  $sortKeys
     */
    private function parentWithChildren(array $sortKeys): AuthzResourceGroup
    {
        $parent = AuthzResourceGroup::factory()->create();

        foreach ($sortKeys as $sortKey) {
            AuthzResourceGroup::factory()->create(['parent_id' => $parent->id, 'sort_key' => $sortKey]);
        }

        return $parent;
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

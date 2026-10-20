<?php

namespace Tests\Feature\Actions\Menu;

use App\Actions\Menu\CreateMenuGroupCategory;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Menu\MenuGroupCategoryTranslation;
use Illuminate\Database\QueryException;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\DeletesResourceGroupTree;
use Tests\Support\TransactionalTestCase;

/**
 * メニューグループカテゴリを作る(docs の 設計/メニュー/表.md 1.7 の 1、認可との結び付け.md 2.2: カテゴリと表示名と、同じトランザクションで
 * カテゴリの認可のリソース(im-menu-group-cat-<ID>、親はセット im-menu-group、URI im-menu-group://category/<ID>、表示名を写す)。失敗で両方が巻き戻る)。
 * ID は保存された(DB が長さを超えた空白を切り詰めた)コードから作る。
 */
final class CreateMenuGroupCategoryTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use DeletesResourceGroupTree;

    public function test_creates_a_category_and_its_resource_with_the_same_display_names(): void
    {
        $set = AuthzResourceGroup::query()->where('code', 'im-menu-group')->sole();
        $resources = AuthzResource::query()->pluck('id');

        $category = $this->create('example_nav_pc', ['ja' => '例のナビ（PC用）', 'en' => 'Example navigation (PC)'], isSelectable: true);

        $this->assertSame(['example_nav_pc', true, false], [$category->code, $category->is_selectable, $category->is_editable]);
        $this->assertEqualsCanonicalizing(['例のナビ（PC用）', 'Example navigation (PC)'], $category->menuGroupCategoryTranslations()->get()->map(fn (MenuGroupCategoryTranslation $translation) => $translation->display_name)->all());
        $resource = AuthzResource::query()->whereNotIn('id', $resources)->sole();
        $group = $resource->authzResourceGroup()->firstOrFail();
        $this->assertSame(['im-menu-group', 'im-menu-group://category/example_nav_pc'], [$resource->resource_type, $resource->uri]);
        $this->assertSame(['im-menu-group-cat-example_nav_pc', $set->id, 0], [$group->code, $group->parent_id, $group->sort_key]);
        $this->assertEqualsCanonicalizing(
            [['例のナビ（PC用）', null], ['Example navigation (PC)', null]],
            $group->authzResourceGroupTranslations()->get()->map(fn (AuthzResourceGroupTranslation $translation) => [$translation->display_name, $translation->description])->all(),
        );
    }

    public function test_builds_the_resource_from_the_stored_code_when_the_database_trims_trailing_spaces_over_the_length(): void
    {
        $resources = AuthzResource::query()->pluck('id');
        $code = str_repeat('a', 100);

        $category = $this->create("{$code} ");

        $resource = AuthzResource::query()->whereNotIn('id', $resources)->sole();
        $this->assertSame(
            [$code, "im-menu-group-cat-{$code}", "im-menu-group://category/{$code}"],
            [$category->code, $resource->authzResourceGroup()->firstOrFail()->code, $resource->uri],
        );
    }

    public function test_rejects_a_category_without_the_resource_group_set_and_writes_nothing(): void
    {
        $this->deleteResourceGroupTree('im-menu-group');
        $counts = [MenuGroupCategory::query()->count(), AuthzResourceGroup::query()->count()];

        $this->assertRejected('resource_group', fn () => $this->create('example_pc'));
        $this->assertSame($counts, [MenuGroupCategory::query()->count(), AuthzResourceGroup::query()->count()]);
    }

    public function test_rolls_back_the_category_when_its_resource_cannot_be_created(): void
    {
        AuthzResourceGroup::factory()->create(['code' => 'im-menu-group-cat-example_pc']);
        $counts = $this->counts();

        $this->assertThrows(fn () => $this->create('example_pc'), QueryException::class);
        $this->assertSame($counts, $this->counts());
    }

    /**
     * カテゴリ・リソース・リソースグループの件数。
     *
     * @return list<int>
     */
    private function counts(): array
    {
        return [MenuGroupCategory::query()->count(), AuthzResource::query()->count(), AuthzResourceGroup::query()->count()];
    }

    /**
     * @param  array<string, string>  $displayNames
     */
    private function create(string $code, array $displayNames = [], bool $isSelectable = false): MenuGroupCategory
    {
        return app(CreateMenuGroupCategory::class)->execute($code, $displayNames, $isSelectable);
    }
}

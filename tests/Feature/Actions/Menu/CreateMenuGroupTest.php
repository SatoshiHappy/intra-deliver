<?php

namespace Tests\Feature\Actions\Menu;

use App\Actions\Menu\CreateMenuGroup;
use App\Actions\Menu\MenuFolderInput;
use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroup;
use App\Models\Menu\MenuGroupCategory;
use Illuminate\Database\QueryException;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * メニューグループを作る(docs の 設計/メニュー/表.md 1.7 の 2、認可との結び付け.md 2.2: ルートのメニュー(folder・親なし・ID = グループ ID)・表示名・
 * グループ・カテゴリへの所属と、同じトランザクションでグループの認可のリソース(im-menu-group-<ID>、親は作る先のカテゴリのリソースグループ、
 * URI im-menu-group://group/<ID>、表示名を写す)。失敗ですべて巻き戻る)。ID は保存された(DB が長さを超えた空白を切り詰めた)グループ ID から作る。
 */
final class CreateMenuGroupTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_creates_the_root_folder_the_group_its_inclusion_and_its_resource(): void
    {
        [$category, $categoryGroup] = $this->categoryWithResource('example_nav_pc');

        $group = $this->create($category->id, new MenuFolderInput('global-nav-home', ['ja' => 'ホーム'], sortNumber: 5, imagePath: '/images/home.png', notes: '説明', icon16: 'i16', icon32: 'i32', icon48: 'i48'));

        $root = $group->menu()->firstOrFail();
        $this->assertSame(
            ['global-nav-home', null, MenuType::Folder, 5, null, MenuMethod::Get, '/images/home.png', '説明', 'i16', 'i32', 'i48'],
            [$root->code, $root->parent_id, $root->type, $root->sort_number, $root->url, $root->method, $root->image_path, $root->notes, $root->icon_16, $root->icon_32, $root->icon_48],
        );
        $this->assertSame(['ホーム'], $root->menuTranslations()->pluck('display_name')->all());
        $this->assertSame([$category->id], $group->menuGroupCategoryInclusions()->pluck('menu_group_category_id')->all());
        $resource = AuthzResource::query()->where('uri', 'im-menu-group://group/global-nav-home')->sole();
        $resourceGroup = $resource->authzResourceGroup()->firstOrFail();
        $this->assertSame(['im-menu-group', 'im-menu-group-global-nav-home', $categoryGroup->id], [$resource->resource_type, $resourceGroup->code, $resourceGroup->parent_id]);
        $this->assertSame(['ホーム'], $resourceGroup->authzResourceGroupTranslations()->pluck('display_name')->all());
    }

    public function test_builds_the_resource_from_the_stored_group_id_when_the_database_trims_trailing_spaces_over_the_length(): void
    {
        [$category] = $this->categoryWithResource('c');
        $resources = AuthzResource::query()->pluck('id');
        $code = str_repeat('a', 100);

        $group = $this->create($category->id, new MenuFolderInput("{$code} ", []));

        $resource = AuthzResource::query()->whereNotIn('id', $resources)->sole();
        $this->assertSame(
            [$code, "im-menu-group-{$code}", "im-menu-group://group/{$code}"],
            [$group->menu()->firstOrFail()->code, $resource->authzResourceGroup()->firstOrFail()->code, $resource->uri],
        );
    }

    public function test_rejects_a_category_that_does_not_exist(): void
    {
        $counts = $this->counts();

        $this->assertRejected('menu_group_category_id', fn () => $this->create(MenuGroupCategory::query()->max('id') + 1, new MenuFolderInput('g', [])));
        $this->assertSame($counts, $this->counts());
    }

    public function test_rolls_back_everything_when_the_category_has_no_resource_group(): void
    {
        $category = MenuGroupCategory::factory()->create();
        $counts = $this->counts();

        $this->assertRejected('resource_group', fn () => $this->create($category->id, new MenuFolderInput('g', [])));
        $this->assertSame($counts, $this->counts());
    }

    public function test_rolls_back_everything_when_the_resource_of_the_group_cannot_be_created(): void
    {
        [$category] = $this->categoryWithResource('c');
        AuthzResourceGroup::factory()->create(['code' => 'im-menu-group-g']);
        $counts = $this->counts();

        $this->assertThrows(fn () => $this->create($category->id, new MenuFolderInput('g', [])), QueryException::class);
        $this->assertSame($counts, $this->counts());
    }

    /**
     * カテゴリと、そのリソースグループ(初期データのセットの下)。
     *
     * @return array{MenuGroupCategory, AuthzResourceGroup}
     */
    private function categoryWithResource(string $code): array
    {
        $set = AuthzResourceGroup::query()->where('code', 'im-menu-group')->sole();

        return [
            MenuGroupCategory::factory()->create(['code' => $code]),
            AuthzResourceGroup::factory()->create(['code' => "im-menu-group-cat-{$code}", 'parent_id' => $set->id]),
        ];
    }

    /**
     * メニュー・グループ・リソースの件数。
     *
     * @return list<int>
     */
    private function counts(): array
    {
        return [Menu::query()->count(), MenuGroup::query()->count(), AuthzResource::query()->count()];
    }

    private function create(int $categoryId, MenuFolderInput $root): MenuGroup
    {
        return app(CreateMenuGroup::class)->execute($categoryId, $root);
    }
}

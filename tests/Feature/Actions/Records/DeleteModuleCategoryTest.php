<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuItemInput;
use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\DeleteModuleCategory;
use App\Actions\Records\ReorderRecordModuleItems;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsModuleCategories;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * モジュール分類を消す(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.4、画面と検査.md 6 節の「消す」): ほかの分類に属さない業務モジュールは
 * 「業務」の直下の末尾に(2 木。中の並びの順に)、ほかの分類にも属すものはそちらに残り直下にできない。非表示の業務モジュールのはぐれたアイテムは消えるだけ。
 * 2 つのフォルダが消え、残る分類が付け直される。下位の業務処理の拒否はキー category で、全体が巻き戻る。
 * 初期データのプロジェクト(直下 100)と課題(直下 110)を使う。
 */
final class DeleteModuleCategoryTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsModuleCategories;
    use ReadsRecordModuleMenus;

    public function test_modules_only_in_the_category_return_to_the_end_of_the_direct_items_in_the_order_inside_and_the_others_stay(): void
    {
        app(CreateRecordModule::class)->execute(['code' => 'example_extra', 'name' => '備品']);
        $deleted = $this->createCategory('営業');
        $kept = $this->createCategory('総務');
        $last = $this->createCategory('経理');
        $this->update('project', ['categories' => [$deleted]]);
        $this->update('project_issue', ['categories' => [$deleted, $kept]]);
        $this->update('example_extra', ['categories' => [$deleted]]);
        app(ReorderRecordModuleItems::class)->execute($deleted, ['example_extra', 'project_issue', 'project']);

        app(DeleteModuleCategory::class)->execute($deleted);

        foreach (WorkMenuGroup::cases() as $group) {
            $this->assertNull(Menu::query()->where('code', $group->categoryFolderCode($deleted))->first());
            $this->assertSame([
                "{$group->value}-cat-{$kept}" => 10, "{$group->value}-cat-{$last}" => 11,
                "{$group->value}-records-example_extra" => 100, "{$group->value}-records-project" => 110,
            ], array_filter($this->children($group->value), fn (int $sortNumber) => $sortNumber >= 10));
            $this->assertSame([$group->categoryItemCode($kept, 'project_issue') => 100], $this->children($group->categoryFolderCode($kept)));
        }
    }

    public function test_the_stray_items_of_a_hidden_module_are_only_removed(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['is_visible' => false]);

        // サイトマップにだけ残ったアイテム(global-nav-main にあれば表示中 = モジュール分類/README.md 1.4)
        $folder = Menu::query()->where('code', "sitemap-main-cat-{$categoryId}")->sole();
        app(AddMenu::class)->execute($folder->id, new MenuItemInput("sitemap-main-cat-{$categoryId}-project", '/records/data/project', ['ja' => 'プロジェクト']));

        app(DeleteModuleCategory::class)->execute($categoryId);

        $this->assertSame([], $this->placements('project'));
    }

    public function test_the_items_of_a_code_without_a_module_are_only_removed(): void
    {
        $categoryId = $this->createCategory('営業');
        $folder = Menu::query()->where('code', "global-nav-main-cat-{$categoryId}")->sole();
        app(AddMenu::class)->execute($folder->id, new MenuItemInput("global-nav-main-cat-{$categoryId}-example_nothing", '/records/data/project', ['ja' => '無い']));
        $menus = Menu::query()->count();

        app(DeleteModuleCategory::class)->execute($categoryId);

        $this->assertSame($menus - 3, Menu::query()->count());
        $this->assertSame([], $this->placements('example_nothing'));
    }

    public function test_a_rejection_of_the_menu_rolls_back_everything_with_the_key_category(): void
    {
        $categoryId = $this->createCategory('営業');
        $this->update('project', ['categories' => [$categoryId]]);
        $item = Menu::query()->where('code', "sitemap-main-cat-{$categoryId}-project")->sole();
        Menu::factory()->item()->create(['code' => 'example-copy', 'original_menu_id' => $item->id]);
        $before = $this->authorizationAndMenus();

        $errors = $this->validationErrors(fn () => app(DeleteModuleCategory::class)->execute($categoryId));

        $this->assertSame(['category' => "コピーの元のメニューは削除できません: sitemap-main-cat-{$categoryId}-project"], $errors);
        $this->assertSame($before, $this->authorizationAndMenus());
    }
}

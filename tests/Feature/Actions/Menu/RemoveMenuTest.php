<?php

namespace Tests\Feature\Actions\Menu;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\MenuItemInput;
use App\Actions\Menu\RemoveMenu;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuArgument;
use App\Models\Menu\MenuTranslation;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * メニューを消す(docs の 設計/メニュー/表.md 1.7 の 4): 渡されたメニュー ID のうち、あるものだけを渡された順に消し(無いものは飛ばす。同じ ID は 1 つ)、
 * 消したメニュー ID を返す。表示名・引数は連鎖で消える。親を持つフォルダ(グループのルートでない)も消せる(モジュール分類/業務処理.md 3.8)。
 * 種別が item でも親を持つ folder でもない・子を持つ・コピーの元のメニューが 1 つでもあれば拒み、何も消さない。
 */
final class RemoveMenuTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    public function test_removes_the_existing_items_in_the_given_order_with_their_names_and_arguments_and_skips_the_missing_ones(): void
    {
        $parent = Menu::factory()->create(['type' => MenuType::Folder]);
        $first = $this->addItem($parent, 'example-first');
        $second = $this->addItem($parent, 'example-second');
        $menus = Menu::query()->count();

        $removed = app(RemoveMenu::class)->execute(['example-second', 'example-nothing', 'example-first', 'example-second']);

        $this->assertSame(['example-second', 'example-first'], $removed);
        $this->assertSame($menus - 2, Menu::query()->count());
        $this->assertSame(0, MenuTranslation::query()->whereIn('menu_id', [$first->id, $second->id])->count());
        $this->assertSame(0, MenuArgument::query()->whereIn('menu_id', [$first->id, $second->id])->count());
        $this->assertModelExists($parent);
        $this->assertSame([], app(RemoveMenu::class)->execute(['example-nothing']));
    }

    public function test_removes_a_folder_without_children_that_has_a_parent(): void
    {
        $root = Menu::factory()->create(['type' => MenuType::Folder]);
        $folder = app(AddMenu::class)->execute($root->id, new MenuFolderInput('example-folder', ['ja' => 'フォルダ', 'en' => 'Folder']));

        $this->assertSame(['example-folder'], app(RemoveMenu::class)->execute(['example-folder']));

        $this->assertModelMissing($folder);
        $this->assertSame(0, MenuTranslation::query()->where('menu_id', $folder->id)->count());
        $this->assertModelExists($root);
    }

    public function test_rejects_a_group_root_a_folder_with_a_child_an_external_link_an_item_with_a_child_and_the_original_of_a_copy_and_removes_nothing(): void
    {
        $parent = Menu::factory()->create(['type' => MenuType::Folder]);
        $item = $this->addItem($parent, 'example-item');
        Menu::factory()->create(['code' => 'example-root', 'type' => MenuType::Folder]);
        $this->addItem(app(AddMenu::class)->execute($parent->id, new MenuFolderInput('example-folder', ['ja' => 'フォルダ'])), 'example-child');
        $external = Menu::factory()->create(['code' => 'example-external', 'type' => MenuType::External, 'url' => 'https://example.com/']);
        $parentItem = Menu::factory()->item()->create(['code' => 'example-parent-item']);
        Menu::factory()->item()->create(['parent_id' => $parentItem->id]);
        $original = Menu::factory()->item()->create(['code' => 'example-original']);
        Menu::factory()->item()->create(['original_menu_id' => $original->id]);
        $menus = Menu::query()->count();

        $this->assertSame([
            ['menu' => '削除できるのはメニューアイテムと、グループのルートでないメニューフォルダだけです: example-root'],
            ['menu' => '子のあるメニューは削除できません: example-folder'],
            ['menu' => '削除できるのはメニューアイテムと、グループのルートでないメニューフォルダだけです: example-external'],
            ['menu' => '子のあるメニューは削除できません: example-parent-item'],
            ['menu' => 'コピーの元のメニューは削除できません: example-original'],
        ], array_map(
            fn (string $code) => $this->validationErrors(fn () => app(RemoveMenu::class)->execute(['example-item', $code])),
            ['example-root', 'example-folder', 'example-external', 'example-parent-item', 'example-original'],
        ));

        $this->assertSame($menus, Menu::query()->count());
        $this->assertModelExists($item);
        $this->assertModelExists($external);
    }

    private function addItem(Menu $parent, string $code): Menu
    {
        return app(AddMenu::class)->execute($parent->id, new MenuItemInput($code, '/', ['ja' => '名前', 'en' => 'Name'], arguments: ['tab' => '1']));
    }
}

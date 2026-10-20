<?php

namespace Tests\Feature\Actions\Menu;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\MenuItemInput;
use App\Actions\Menu\UpdateMenuDisplay;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * メニューの表示名とアイコンを変える(docs の 設計/メニュー/表.md 1.7 の 5): 渡されたメニュー ID のうち、あるものだけを渡された順に変え(無いものは飛ばす。
 * 同じ ID は 1 つ。あるものが無ければロケールも確かめない)、変えたメニュー ID を返す。入力にあるロケールは行があれば更新し、無ければ作る。入力に無いロケールの行・ほかの列は変えない。
 * 外部メニューリンクが 1 つでもあるとき・無いロケールは拒み、何も変えない。
 */
final class UpdateMenuDisplayTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_replaces_the_display_name_of_the_given_locale_and_the_icon_of_an_item(): void
    {
        $item = $this->add(new MenuItemInput('example-item', '/', ['ja' => '旧名', 'en' => 'Old'], sortNumber: 7, notes: 'n', icon16: 'a', icon32: 'table'));
        $before = $item->refresh()->only(['parent_id', 'sort_number', 'type', 'url', 'notes', 'icon_16', 'icon_48', 'original_menu_id']);

        $updated = app(UpdateMenuDisplay::class)->execute(['example-item'], ['ja' => '新名'], 'truck');

        $this->assertSame(['example-item'], $updated);
        $this->assertSame(['ja' => '新名', 'en' => 'Old'], $this->displayNames($item));
        $this->assertSame('truck', $item->refresh()->icon_32);
        $this->assertSame($before, $item->only(array_keys($before)));
    }

    public function test_creates_the_row_of_a_locale_without_a_row_and_clears_the_icon_of_a_folder(): void
    {
        $folder = $this->add(new MenuFolderInput('example-folder', ['ja' => '旧名'], icon32: 'table'));

        app(UpdateMenuDisplay::class)->execute(['example-folder'], ['ja' => '新名', 'en' => 'New'], null);

        $this->assertSame(['ja' => '新名', 'en' => 'New'], $this->displayNames($folder));
        $this->assertNull($folder->refresh()->icon_32);
    }

    public function test_skips_a_missing_menu_and_returns_the_changed_ones_in_the_given_order(): void
    {
        $first = $this->add(new MenuItemInput('example-first', '/', ['ja' => '旧名']));
        $second = $this->add(new MenuItemInput('example-second', '/', ['ja' => '旧名']));
        $menus = Menu::query()->count();

        $updated = app(UpdateMenuDisplay::class)->execute(['example-second', 'example-nothing', 'example-first', 'example-second'], ['ja' => '新名'], 'truck');

        $this->assertSame(['example-second', 'example-first'], $updated);
        $this->assertSame([['ja' => '新名'], ['ja' => '新名']], [$this->displayNames($first), $this->displayNames($second)]);
        $this->assertSame($menus, Menu::query()->count());
        // あるものが 1 つも無ければ、ロケールを確かめずに何もしない
        $this->assertSame([], app(UpdateMenuDisplay::class)->execute(['example-nothing'], ['ja' => '新名', 'xx' => '?'], 'truck'));
    }

    public function test_rejects_an_external_link_among_the_menus_and_an_unknown_locale_and_changes_nothing(): void
    {
        $item = $this->add(new MenuItemInput('example-item', '/', ['ja' => '旧名'], icon32: 'table'));
        $external = Menu::factory()->create(['code' => 'example-external', 'type' => MenuType::External, 'url' => 'https://example.com/']);

        $this->assertRejected('menu', fn () => app(UpdateMenuDisplay::class)->execute(['example-item', 'example-external'], ['ja' => '新名'], 'truck'));
        $this->assertRejected('locale', fn () => app(UpdateMenuDisplay::class)->execute(['example-item'], ['ja' => '新名', 'xx' => '?'], 'truck'));

        $this->assertSame(['ja' => '旧名'], $this->displayNames($item));
        $this->assertSame('table', $item->refresh()->icon_32);
        $this->assertSame([], $this->displayNames($external));
    }

    private function add(MenuFolderInput|MenuItemInput $input): Menu
    {
        return app(AddMenu::class)->execute(Menu::factory()->create(['type' => MenuType::Folder])->id, $input);
    }

    /**
     * ロケールのコード → 表示名(ja・en の順)。
     *
     * @return array<string, string>
     */
    private function displayNames(Menu $menu): array
    {
        $rows = MenuTranslation::query()->with('locale')->where('menu_id', $menu->id)->get();
        $byLocale = [];

        foreach (['ja', 'en'] as $locale) {
            $row = $rows->first(fn (MenuTranslation $translation) => $translation->locale?->code === $locale);

            if ($row !== null) {
                $byLocale[$locale] = $row->display_name;
            }
        }

        return $byLocale;
    }
}

<?php

namespace Tests\Feature\Actions\Menu;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\MenuItemInput;
use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * メニューを足す(docs の 設計/メニュー/表.md 1.7 の 3: 親は folder に限る。folder の URL は NULL・メソッドは get。item の URL は、メソッドとパスで
 * 照合したアプリのルートがあるアプリの中のパスか、外部サイトの絶対 URL で、それ以外は拒む)。
 */
final class AddMenuTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    /**
     * @return array<string, array{string, MenuMethod}>
     */
    public static function urlsToReject(): array
    {
        return [
            'プロトコル相対の URL' => ['//evil.example/', MenuMethod::Get],
            'バックスラッシュ' => ['/\\evil.example/', MenuMethod::Get],
            'クエリ文字列' => ['/?tab=1', MenuMethod::Get],
            'フラグメント' => ['/#top', MenuMethod::Get],
            '/ で始まらない相対パス' => ['menu/sitemap', MenuMethod::Get],
            'http(s) でないスキーム' => ['javascript:alert(1)', MenuMethod::Get],
            '大文字のスキーム' => ['HTTPS://example.com/', MenuMethod::Get],
            'ルートの無いパス' => ['/no-such-path', MenuMethod::Get],
            'メソッドの合わないパス' => ['/', MenuMethod::Post],
            'タブ(ブラウザでは //evil.example)' => ["/\t/evil.example", MenuMethod::Get],
            '改行' => ["/\n/x", MenuMethod::Get],
            '空白' => ['/a b', MenuMethod::Get],
            '.. の区切り' => ['/a/../b', MenuMethod::Get],
            '. の区切り' => ['/a/./b', MenuMethod::Get],
            '%2e で書いた .. の区切り' => ['/%2e%2e/b', MenuMethod::Get],
            '大文字小文字の混ざった %2E で書いた .. の区切り' => ['/%2E%2e/b', MenuMethod::Get],
            'ASCII でない文字' => ['/日本語', MenuMethod::Get],
        ];
    }

    public function test_adds_a_folder_with_a_null_url_and_the_get_method(): void
    {
        $folder = $this->add($this->folder(), new MenuFolderInput('reports', ['ja' => 'レポート'], sortNumber: 2, notes: '説明'));

        $this->assertSame([MenuType::Folder, null, MenuMethod::Get, 2, '説明'], [$folder->type, $folder->url, $folder->method, $folder->sort_number, $folder->notes]);
        $this->assertSame(['レポート'], $folder->menuTranslations()->pluck('display_name')->all());
    }

    public function test_adds_an_item_with_every_column_and_its_arguments_for_an_app_route(): void
    {
        Route::post('/test/items', fn () => 'stored')->name('test.items.store');
        $original = Menu::factory()->item()->create();

        $item = $this->add($this->folder(), new MenuItemInput(
            'items-store', '/test/items', [], sortNumber: 3, method: MenuMethod::Post, imagePath: '/i.png', notes: 'n', icon16: 'a', icon32: 'b', icon48: 'c',
            isIframeUsed: true, isPopupUsed: true, originalMenuId: $original->id, arguments: ['mode' => 'new', 'tab' => '2'],
        ));

        $this->assertSame(
            [MenuType::Item, '/test/items', MenuMethod::Post, 3, '/i.png', 'n', 'a', 'b', 'c', true, true, $original->id],
            [$item->type, $item->url, $item->method, $item->sort_number, $item->image_path, $item->notes, $item->icon_16, $item->icon_32, $item->icon_48, $item->is_iframe_used, $item->is_popup_used, $item->original_menu_id],
        );
        $this->assertSame(['mode' => 'new', 'tab' => '2'], $item->menuArguments()->orderBy('name')->pluck('value', 'name')->all());
    }

    public function test_adds_an_item_for_an_external_site_without_matching_routes(): void
    {
        $item = $this->add($this->folder(), new MenuItemInput('docs', 'https://example.com/docs?page=1#top', []));

        $this->assertSame('https://example.com/docs?page=1#top', $item->url);
    }

    public function test_adds_menus_at_any_depth(): void
    {
        $nested = $this->add($this->add($this->folder(), new MenuFolderInput('level-1', [])), new MenuFolderInput('level-2', []));

        $item = $this->add($nested, new MenuItemInput('home', '/', []));

        $this->assertSame($nested->id, $item->parent_id);
    }

    #[DataProvider('urlsToReject')]
    public function test_rejects_an_item_url_that_is_neither_an_app_route_path_nor_an_external_url(string $url, MenuMethod $method): void
    {
        $folder = $this->folder();
        $menus = Menu::query()->count();

        $this->assertRejected('url', fn () => $this->add($folder, new MenuItemInput('item', $url, [], method: $method)));
        $this->assertSame($menus, Menu::query()->count());
    }

    public function test_rejects_a_parent_that_is_not_a_folder(): void
    {
        $item = Menu::factory()->item()->create();
        $external = Menu::factory()->create(['type' => MenuType::External]);
        $menus = Menu::query()->count();

        $this->assertRejected('parent_id', fn () => $this->add($item, new MenuFolderInput('child', [])));
        $this->assertRejected('parent_id', fn () => $this->add($external, new MenuItemInput('child', '/', [])));
        $this->assertSame($menus, Menu::query()->count());
    }

    public function test_rejects_a_parent_that_does_not_exist(): void
    {
        $menus = Menu::query()->count();
        $missingId = (int) Menu::query()->max('id') + 1;

        $this->assertRejected('parent_id', fn () => app(AddMenu::class)->execute($missingId, new MenuFolderInput('child', [])));
        $this->assertSame($menus, Menu::query()->count());
    }

    private function folder(): Menu
    {
        return Menu::factory()->create(['type' => MenuType::Folder]);
    }

    private function add(Menu $parent, MenuFolderInput|MenuItemInput $input): Menu
    {
        return app(AddMenu::class)->execute($parent->id, $input);
    }
}

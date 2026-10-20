<?php

namespace Tests\Feature\Queries\Menu;

use App\Actions\Authz\CreateRouteResource;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\CreateMenuGroupCategory;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\MenuItemInput;
use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * メニューの表示判定(docs の 設計/メニュー/表示.md 3.1): カテゴリのグループは read、アイテムは照合したルートの execute で判定し、
 * 外部サイトの URL は照合に回さず常に出す。子孫に表示するアイテムの無いフォルダ・グループと外部メニューリンクは出さない。並びは sort_number・code、
 * 表示名はロケールの行で、無ければ null。返す形に使わない列と method を含めない。判定を求める者は段 1b のゲスト。
 *
 * テストのルートと認可は {@see ArrangesMenuDisplay} で足す。href と、平らにした表示するアイテムは MenuDisplayItemsTest。
 */
final class MenuDisplayQueryTest extends TransactionalTestCase
{
    use ArrangesMenuDisplay;

    /**
     * @return array<string, array{string|null, MenuMethod}>
     */
    public static function urlsNotShown(): array
    {
        return [
            'ルートの無いパス' => ['/no-such-path', MenuMethod::Get],
            'メソッドの合わないパス' => ['/test/permitted', MenuMethod::Post],
            '不正な URI(末尾の空白)' => ['/test/permitted ', MenuMethod::Get],
            '不正な URI(タブ)' => ["/test/per\tmitted", MenuMethod::Get],
            'クエリ文字列' => ['/test/permitted?tab=1', MenuMethod::Get],
            'プロトコル相対の URL' => ['//example.com/test/permitted', MenuMethod::Get],
            'http(s) でないスキーム' => ['javascript:alert(1)', MenuMethod::Get],
            '大文字のスキーム' => ['HTTPS://example.com/', MenuMethod::Get],
            '/ で始まらない相対パス' => ['test/permitted', MenuMethod::Get],
            'URL なし' => [null, MenuMethod::Get],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->addTestRoutes();
    }

    public function test_shows_only_the_groups_of_the_category_whose_read_is_permitted(): void
    {
        $this->arrangeAuthorization();
        $this->item($this->group('permitted'), 'permitted-home', '/test/permitted');
        $this->item($this->group('not-readable', isReadPermitted: false), 'not-readable-home', '/test/permitted');
        $this->item($this->group('other-category', self::SITEMAP), 'other-category-home', '/test/permitted');

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame(['permitted', 'permitted/permitted-home'], $paths);
    }

    public function test_shows_only_the_items_whose_route_execute_is_permitted(): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main');
        $this->item($group, 'permitted', '/test/permitted');
        $this->item($group, 'forbidden', '/test/forbidden');
        $this->item($group, 'posted', '/test/posted', method: MenuMethod::Post);

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame(['main', 'main/permitted', 'main/posted'], $paths);
    }

    public function test_shows_folders_nested_at_any_depth(): void
    {
        $this->arrangeAuthorization();
        $this->item($this->folder($this->folder($this->group('main'), 'level-1'), 'level-2'), 'deep', '/test/permitted');

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame(['main', 'main/level-1', 'main/level-1/level-2', 'main/level-1/level-2/deep'], $paths);
    }

    public function test_hides_folders_and_groups_without_a_visible_item_among_their_descendants(): void
    {
        $this->arrangeAuthorization();
        $main = $this->group('main');
        $this->folder($main, 'empty');
        $this->item($this->folder($main, 'forbidden-only'), 'forbidden', '/test/forbidden');
        $this->folder($this->folder($main, 'nested-empty'), 'nested-empty-child');
        $this->item($main, 'visible', '/test/permitted');
        $this->folder($this->group('only-empty-folders'), 'empty-2');
        $this->item($this->group('only-forbidden-items'), 'forbidden-2', '/test/forbidden');
        $this->group('without-children');

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame(['main', 'main/visible'], $paths);
    }

    public function test_always_shows_external_site_items_without_matching_them_against_the_routes(): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main');
        $this->item($group, 'external-https', 'https://example.com/docs?page=1#top', ['ja' => '資料']);
        $this->item($group, 'external-same-path', 'http://localhost/test/forbidden', ['ja' => '同じパス'], sortNumber: 1);

        $children = $this->visibleGroups()[0]['children'];

        $this->assertSame([
            ['code' => 'external-https', 'type' => 'item', 'name' => '資料', 'icon' => null, 'href' => 'https://example.com/docs?page=1#top', 'isExternal' => true],
            ['code' => 'external-same-path', 'type' => 'item', 'name' => '同じパス', 'icon' => null, 'href' => 'http://localhost/test/forbidden', 'isExternal' => true],
        ], $children);
    }

    #[DataProvider('urlsNotShown')]
    public function test_hides_items_whose_url_is_in_neither_form_or_matches_no_route(?string $url, MenuMethod $method): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main');
        $this->item($group, 'visible', '/test/permitted');
        Menu::factory()->create(['parent_id' => $group->id, 'code' => 'hidden', 'type' => MenuType::Item, 'url' => $url, 'method' => $method]);

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame(['main', 'main/visible'], $paths);
    }

    public function test_hides_an_item_whose_url_is_outside_the_app_path_form_even_if_a_permitted_route_would_match(): void
    {
        Route::get('/{a}/{b}', fn (string $a, string $b) => $a.$b)->name('test.any');
        $this->arrangeAuthorization();
        $this->permitGuest(app(CreateRouteResource::class)->execute('test.any', [])->authz_resource_group_id, 'service', 'execute');
        $group = $this->group('main');
        $this->item($group, 'visible', '/a/b');
        Menu::factory()->create(['parent_id' => $group->id, 'code' => 'hidden', 'type' => MenuType::Item, 'url' => "/\x01/evil.example", 'method' => MenuMethod::Get]);

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame(['main', 'main/visible'], $paths);
    }

    public function test_shows_a_group_and_category_created_with_ids_whose_trailing_space_over_the_length_the_database_trims(): void
    {
        $this->arrangeAuthorization();
        $code = str_repeat('a', 100);
        app(CreateMenuGroupCategory::class)->execute("{$code} ", []);
        $this->item($this->group("{$code} ", $code), 'visible', '/test/permitted');

        $paths = $this->paths($this->visibleGroups($code));

        $this->assertSame([$code, "{$code}/visible"], $paths);
    }

    public function test_does_not_show_external_menu_links(): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main');
        $this->item($group, 'visible', '/test/permitted');
        Menu::factory()->create(['parent_id' => $group->id, 'code' => 'external-link', 'type' => MenuType::External, 'url' => 'https://example.com/']);

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame(['main', 'main/visible'], $paths);
    }

    public function test_orders_groups_and_children_by_sort_number_then_code(): void
    {
        $this->arrangeAuthorization();
        $this->item($this->group('group-b', sortNumber: 1), 'b-item', '/test/permitted');
        $this->item($this->group('group-a', sortNumber: 1), 'a-item', '/test/permitted');
        $first = $this->group('group-c', sortNumber: 0);
        $this->item($first, 'item-b', '/test/permitted');
        $this->item($first, 'item-a', '/test/permitted');
        $this->item($this->folder($first, 'folder-z', sortNumber: -1), 'z-item', '/test/permitted');
        $this->item($first, 'item-c', '/test/permitted', sortNumber: -2);

        $paths = $this->paths($this->visibleGroups());

        $this->assertSame([
            'group-c', 'group-c/item-c', 'group-c/folder-z', 'group-c/folder-z/z-item', 'group-c/item-a', 'group-c/item-b',
            'group-a', 'group-a/a-item',
            'group-b', 'group-b/b-item',
        ], $paths);
    }

    public function test_returns_a_null_name_for_a_menu_without_a_display_name_in_the_locale(): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main', names: ['en' => 'Menu']);
        $this->item($group, 'both', '/test/permitted', ['ja' => 'ホーム', 'en' => 'Home']);
        $this->item($group, 'english-only', '/test/permitted', ['en' => 'Sitemap']);

        $groups = $this->visibleGroups();

        $this->assertSame([null, ['ホーム', null]], [$groups[0]['name'], array_column($groups[0]['children'], 'name')]);
    }

    /**
     * icon は icon_32 の値をそのまま返し、無ければ null(docs の 設計/画面の意匠/サイドバーとアイコン.md 5.3)。icon_16・icon_48 は返さない。
     */
    public function test_returns_only_the_display_shape_without_unused_columns_or_the_method(): void
    {
        $this->arrangeAuthorization();
        $folder = app(AddMenu::class)->execute($this->group('main', names: ['ja' => 'メニュー'])->id, new MenuFolderInput(
            'reports', ['ja' => 'レポート'], imagePath: '/f.png', notes: 'n', icon16: 'a', icon48: 'c',
        ));
        app(AddMenu::class)->execute($folder->id, new MenuItemInput(
            'post-item', '/test/posted', ['ja' => '送る'], method: MenuMethod::Post, imagePath: '/i.png', notes: 'n', icon16: 'a', icon32: 'b', icon48: 'c',
            isIframeUsed: true, isPopupUsed: true, arguments: ['mode' => 'new'],
        ));

        $groups = $this->visibleGroups();

        $this->assertSame([[
            'code' => 'main',
            'name' => 'メニュー',
            'children' => [[
                'code' => 'reports',
                'type' => 'folder',
                'name' => 'レポート',
                'icon' => null,
                'children' => [['code' => 'post-item', 'type' => 'item', 'name' => '送る', 'icon' => 'b', 'href' => '/test/posted?mode=new', 'isExternal' => false]],
            ]],
        ]], $groups);
    }

    /**
     * 木を、メニュー ID を / でつないだ経路の並び(前順)に写す。
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    private function paths(array $nodes, string $prefix = ''): array
    {
        $paths = [];

        foreach ($nodes as $node) {
            $path = $prefix.$node['code'];
            $paths = [...$paths, $path, ...$this->paths($node['children'] ?? [], "{$path}/")];
        }

        return $paths;
    }
}

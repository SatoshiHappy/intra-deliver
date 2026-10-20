<?php

namespace Tests\Feature\Queries\Menu;

use App\Actions\Authz\CreateRouteResource;
use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\MenuItemRoute;
use App\Queries\Menu\Api\MenuItemVisibility;
use App\Queries\Menu\MenuDisplayQuery;
use App\Queries\Menu\MenuItemVisibilityCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Tests\Support\FixedMenuItemVisibility;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 表示判定の追加の判定(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 1.1・1.2)。テストのルートと認可は {@see ArrangesMenuDisplay} で足す。
 */
final class MenuItemVisibilityTest extends TransactionalTestCase
{
    use ArrangesMenuDisplay;
    use UsesSeededRecords;

    private int $registered = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addTestRoutes();
        Route::get('/test/items/{item}', fn (string $item) => $item)->name('test.items');
    }

    /**
     * アイテムの数によらず 1 回。渡すのはルートの execute で表示と決まったアプリの中のパスのアイテムだけ(外部サイト・禁止のルートは渡さない)。
     */
    public function test_calls_the_implementation_once_per_display_with_the_routes_of_the_permitted_items(): void
    {
        $this->arrangeAuthorization();
        $resource = app(CreateRouteResource::class)->execute('test.items', []);
        $this->permitGuest($resource->authz_resource_group_id, 'service', 'execute');
        $group = $this->group('main');
        $permitted = $this->item($group, 'permitted', '/test/permitted');
        $withParameter = $this->item($group, 'with-parameter', '/test/items/7/', sortNumber: 1);
        $this->item($group, 'forbidden', '/test/forbidden', sortNumber: 2);
        $this->item($group, 'external', 'https://example.com/', sortNumber: 3);
        $inFolder = $this->item($this->folder($group, 'folder', sortNumber: 4), 'in-folder', '/test/permitted');
        $visibility = $this->register(new FixedMenuItemVisibility);

        $this->visibleGroups();
        $date = CarbonImmutable::parse('2026-10-04');
        app(MenuDisplayQuery::class)->visibleItems(self::GLOBAL_NAVIGATION, new Requester(null, null), $date, 'ja');

        $expected = [
            $permitted->id => new MenuItemRoute('test.permitted', []),
            $withParameter->id => new MenuItemRoute('test.items', ['item' => '7']),
            $inFolder->id => new MenuItemRoute('test.permitted', []),
        ];
        $this->assertEquals([$expected, $expected], $visibility->received());
    }

    /**
     * 実装の和を隠す。子が全部隠れたフォルダは出ない。渡していないアイテム(外部サイト)の id を答えても隠さない。
     */
    public function test_hides_the_union_of_the_items_the_implementations_hide_and_folders_left_without_items(): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main');
        $kept = $this->item($group, 'kept', '/test/permitted');
        $hidden = $this->item($group, 'hidden', '/test/permitted', sortNumber: 1);
        $external = $this->item($group, 'external', 'https://example.com/', sortNumber: 2);
        $inFolder = $this->item($this->folder($group, 'folder', sortNumber: 3), 'in-folder', '/test/permitted');
        $this->register(new FixedMenuItemVisibility([$hidden->id, $external->id]));
        $this->register(new FixedMenuItemVisibility([$inFolder->id]));

        $children = $this->visibleGroups()[0]['children'];

        $this->assertSame([$kept->code, $external->code], array_column($children, 'code'));
    }

    public function test_hides_the_group_when_the_implementation_hides_all_of_its_items(): void
    {
        $this->arrangeAuthorization();
        $only = $this->item($this->group('hidden-all'), 'only', '/test/permitted');
        $this->register(new FixedMenuItemVisibility([$only->id]));
        $this->item($this->group('kept', sortNumber: 1), 'kept-item', '/test/permitted');

        $this->assertSame(['kept'], array_column($this->visibleGroups(), 'code'));
    }

    /**
     * 起動が登録した実装で、業務の一覧の照合に合う URL の形の違い(末尾の /・パーセント符号化)も同じ業務モジュールの read で隠れる。
     */
    public function test_hides_every_url_form_that_matches_the_list_of_an_unreadable_module(): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main');
        $this->item($group, 'plain', '/records/data/project');
        $this->item($group, 'trailing-slash', '/records/data/project/', sortNumber: 1);
        $this->item($group, 'encoded', '/records/data/%70roject', sortNumber: 2);
        $this->item($group, 'other-module', '/records/data/project_issue/', sortNumber: 3);
        $this->deny('project', RecordModuleAction::Read);

        $this->assertSame(['other-module'], array_column($this->visibleGroups()[0]['children'], 'code'));
    }

    /**
     * 回帰: 実装を登録しないと、業務モジュールの read を禁止しても業務の一覧のアイテムはルートの execute だけで出る(追加の判定の前の結果)。
     */
    public function test_without_implementations_the_display_follows_the_route_execute_alone(): void
    {
        $this->arrangeAuthorization();
        $group = $this->group('main');
        $this->item($group, 'project', '/records/data/project');
        $this->item($group, 'forbidden', '/test/forbidden', sortNumber: 1);
        $this->deny('project', RecordModuleAction::Read);
        $this->app->instance(MenuItemVisibilityCatalog::class, new MenuItemVisibilityCatalog([]));

        $this->assertSame(['project'], array_column($this->visibleGroups()[0]['children'], 'code'));
    }

    /**
     * 起動と同じタグで、テストの実装を登録の順で後ろに足す。
     */
    private function register(FixedMenuItemVisibility $visibility): FixedMenuItemVisibility
    {
        $name = 'test.menu-item-visibility.'.$this->registered++;
        $this->app->instance($name, $visibility);
        $this->app->tag([$name], MenuItemVisibility::TAG);

        return $visibility;
    }
}

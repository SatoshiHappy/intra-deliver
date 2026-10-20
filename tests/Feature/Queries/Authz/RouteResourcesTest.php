<?php

namespace Tests\Feature\Queries\Authz;

use App\Queries\Authz\RouteResources;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Inertia\DevTools\Http\EntriesController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * アプリのルートと、ルートの画面・処理のリソース(docs の 設計/メニュー/認可との結び付け.md 2.3: アプリのルート = 処理が vendor/ の外にあるものと
 * Route::inertia・redirect・view のもの。名前の規則、名前から決まるリソースグループ ID・URI、メソッドとアプリの中のパスでの照合と、
 * アプリの中のパスの形 = 表.md 1.7)。
 *
 * テストのルートはこのファイルのクロージャ(vendor/ の外)で足す。
 */
final class RouteResourcesTest extends TransactionalTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function pathsThatMatchNoAppRoute(): array
    {
        return [
            'ルートの無いパス' => ['GET', '/no-such-path'],
            'メソッドの違い' => ['POST', '/test/items/1'],
            'プロトコル相対の URL' => ['GET', '//evil.example/'],
            'バックスラッシュ' => ['GET', '/test\\items/1'],
            'クエリ文字列' => ['GET', '/test/items/1?x=1'],
            'フラグメント' => ['GET', '/test/items/1#x'],
            '/ で始まらない' => ['GET', 'test/items/1'],
            '絶対 URL' => ['GET', 'https://localhost/test/items/1'],
            'パッケージのルート(ヘルスチェック)' => ['GET', '/up'],
            'タブ(ブラウザでは //evil.example)' => ['GET', "/\t/evil.example"],
            '改行' => ['GET', "/\n/x"],
            '空白' => ['GET', '/a b'],
            '.. の区切り' => ['GET', '/a/../b'],
            '. の区切り' => ['GET', '/a/./b'],
            '%2e で書いた .. の区切り' => ['GET', '/%2e%2e/b'],
            '大文字小文字の混ざった %2E で書いた .. の区切り' => ['GET', '/%2E%2e/b'],
            'ASCII でない文字' => ['GET', '/日本語'],
        ];
    }

    /**
     * 2 段の引数のルート /{a}/{b} に合うが、アプリの中のパスの形でないパス。
     *
     * @return array<string, array{string}>
     */
    public static function pathsOutsideTheAppPathFormThatARouteWouldMatch(): array
    {
        return [
            '制御文字(照合ではルータが _ に置き換える)' => ["/\x01/x"],
            '削除文字' => ["/\x7f/x"],
            '空白' => ['/a b/c'],
            '.. の区切り' => ['/../b'],
            '. の区切り' => ['/./b'],
            '%2e で書いた .. の区切り' => ['/%2E%2e/b'],
            '%2e で書いた . の区切り' => ['/%2e/b'],
            'ASCII でない文字' => ['/日本語/x'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namesAgainstTheRules(): array
    {
        return [
            '区切りが 1 つ' => ['menu'],
            '大文字' => ['Menu.items'],
            '空の区切り' => ['menu..items'],
            '末尾の .' => ['menu.items.'],
            '区切りに使えない文字' => ['menu.items/show'],
            '242 文字' => ['menu.'.str_repeat('a', 237)],
            'ほかのルートの名前の接頭辞' => ['test.admin'],
        ];
    }

    public function test_routes_whose_handlers_are_outside_vendor_or_framework_route_shortcuts_are_app_routes(): void
    {
        $closure = Route::get('/test/closure', fn () => 'ok');
        $redirect = Route::redirect('/test/old', '/');
        $view = Route::view('/test/view', 'app');
        $inertia = Route::inertia('/test/page', 'Menu/Home');

        $this->assertSame([true, true, true, true], array_map(fn (RoutingRoute $route) => $this->routeResources()->isAppRoute($route), [$closure, $redirect, $view, $inertia]));
    }

    public function test_routes_whose_handlers_are_in_vendor_are_not_app_routes(): void
    {
        $vendorController = Route::get('/test/vendor', [EntriesController::class, 'index']);

        $appRouteUris = array_map(fn (RoutingRoute $route) => $route->uri(), $this->routeResources()->appRoutes());

        $this->assertFalse($this->routeResources()->isAppRoute($vendorController));
        $this->assertNotContains('up', $appRouteUris);
        $this->assertNotContains('storage/{path}', $appRouteUris);
        $this->assertContains('/', $appRouteUris);
    }

    public function test_finds_app_routes_by_name_but_not_package_routes(): void
    {
        $this->registerRoutes();

        $this->assertTrue($this->routeResources()->hasAppRouteNamed('test.items.show'));
        $this->assertFalse($this->routeResources()->hasAppRouteNamed('test.items'));
        $this->assertFalse($this->routeResources()->hasAppRouteNamed('storage.local'));
    }

    public function test_accepts_names_that_follow_the_rules(): void
    {
        $this->registerRoutes();

        $this->assertTrue($this->routeResources()->followsNameRules('test.items.show'));
        $this->assertTrue($this->routeResources()->followsNameRules('menu.'.str_repeat('a', 236)));
        $this->assertTrue($this->routeResources()->followsNameRules('test.admin_users-2'));
    }

    #[DataProvider('namesAgainstTheRules')]
    public function test_rejects_names_against_the_rules(string $name): void
    {
        $this->registerRoutes();

        $this->assertFalse($this->routeResources()->followsNameRules($name));
    }

    public function test_builds_the_resource_group_ids_and_the_uri_from_the_name(): void
    {
        $routeResources = $this->routeResources();

        $this->assertSame('http-services.menu.admin.users', $routeResources->resourceGroupCodeOf('menu.admin.users'));
        $this->assertSame('http-services.menu.admin', $routeResources->parentResourceGroupCodeOf('menu.admin.users'));
        $this->assertSame('http-services', $routeResources->parentResourceGroupCodeOf('menu'));
        $this->assertSame('service://menu/admin/users', $routeResources->uriOf('menu.admin.users'));
    }

    public function test_matches_an_app_route_by_method_and_path(): void
    {
        $this->registerRoutes();

        $this->assertSame('test.items.show', $this->routeResources()->match('get', '/test/items/1')?->getName());
        $this->assertSame('test.items.store', $this->routeResources()->match('post', '/test/items')?->getName());
        $this->assertSame('menu.home', $this->routeResources()->match('GET', '/')?->getName());
    }

    public function test_matching_does_not_bind_the_shared_route_to_the_probe_request(): void
    {
        $this->registerRoutes();

        $route = $this->routeResources()->match('get', '/test/items/1');

        $this->assertNotNull($route);
        $this->assertFalse($route->hasParameters());
    }

    /**
     * 束ねた照合: 合ったルートの複製に照合用の要求を束ねて、名前と引数を返す。共有されているルートは束ねない(処理中の要求のルートを書き換えない)。
     */
    public function test_matching_bound_returns_a_bound_copy_with_the_name_and_the_parameters(): void
    {
        $this->registerRoutes();

        $bound = $this->routeResources()->matchBound('get', '/test/items/1/');

        $this->assertSame('test.items.show', $bound?->getName());
        $this->assertSame(['id' => '1'], $bound->parameters());
        $this->assertFalse($this->routeResources()->match('get', '/test/items/1')?->hasParameters());
        $this->assertNull($this->routeResources()->matchBound('post', '/test/items/1'));
    }

    #[DataProvider('pathsThatMatchNoAppRoute')]
    public function test_matches_no_app_route(string $method, string $path): void
    {
        $this->registerRoutes();

        $this->assertNull($this->routeResources()->match($method, $path));
    }

    #[DataProvider('pathsOutsideTheAppPathFormThatARouteWouldMatch')]
    public function test_matches_no_app_route_for_a_path_outside_the_app_path_form_even_if_a_route_would_match(string $path): void
    {
        Route::get('/{a}/{b}', fn (string $a, string $b) => $a.$b)->name('test.any');

        $this->assertNull($this->routeResources()->match('GET', $path));
    }

    public function test_accepts_every_path_character_percent_encoded_non_ascii_characters_and_three_dot_segments_in_an_app_path(): void
    {
        Route::get('/{a}/{b}', fn (string $a, string $b) => $a.$b)->name('test.any');

        $this->assertSame('test.any', $this->routeResources()->match('GET', '/%E3%81%82/x')?->getName());
        $this->assertSame('test.any', $this->routeResources()->match('GET', '/.../x')?->getName());
        $this->assertTrue($this->routeResources()->isAppPath("/a-z_0.9~!$&'()*+,;=:@%20/"));
        $this->assertNull($this->routeResources()->match('GET', '/%E3%81%82'));
    }

    private function registerRoutes(): void
    {
        Route::get('/test/items/{id}', fn (string $id) => $id)->name('test.items.show');
        Route::post('/test/items', fn () => 'stored')->name('test.items.store');
        Route::get('/test/admin', fn () => 'admin')->name('test.admin');
        Route::get('/test/admin/users', fn () => 'users')->name('test.admin.users');
        Route::get('/test/admin-users', fn () => 'users')->name('test.admin_users-2');
    }

    private function routeResources(): RouteResources
    {
        return app(RouteResources::class);
    }
}

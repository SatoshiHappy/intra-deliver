<?php

namespace Tests\Feature\Routing;

use App\Http\Controllers\Web\Menu\SitemapController;
use App\Http\Middleware\Authz\AuthorizeRoute;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Support\FindsAppRouteViolations;
use Tests\Support\TransactionalTestCase;

/**
 * アプリのルートの付け忘れを止める検査(docs の 設計/メニュー/認可との結び付け.md 2.3 の検査 1): アプリのルートはすべて、ルータが実際に掛ける
 * ミドルウェア(withoutMiddleware で外したものを除いた後)に AuthorizeRoute を持ち、名前が規則(形・長さ・ほかの名前の接頭辞にならない・
 * アプリのルートの中で一意・先頭の区切りがそのルートの処理が属するモジュール)に合う。持ち主のモジュール = コントローラは名前空間
 * App\Http\Controllers\{Web,Api}\<M>\ の <M>、Route::inertia はページ名 <M>/… の <M>。Route::redirect・Route::view は処理から持ち主が決まらないので、
 * 宣言したモジュールのどれか。どれにも当たらない処理は違反(ルートの認可は名前だけで決まるので、同じ名前やほかのモジュールの名前が許可を共有しないように)。
 * アプリのルートはホストの条件(Route::domain)とスキームの条件(http・https だけ)を持たない(表示判定の照合がホストとスキームを見ないため)。
 * アプリのルートの引数に明示の結び付け(Route::bind・Route::model)が無い(引数の結び付けはルートの認可より先に走り、行があるかで 404 と 403 が
 * 分かれるため。コントローラの引数にモデルの型を書く結び付けは、入口がモデルに依存できない規則が止める)。
 * あわせて、アプリのルートでないもの(パッケージのルート・/up)が認可の外にあることと、ファイル配信のルートを出さないこと。アプリのルートの処理に
 * クロージャを使わないこと(ルートのキャッシュの後はクロージャが直列化され、定義のあるファイルが分からなくなり、アプリのルートと判定できないため)。
 * 判定は {@see FindsAppRouteViolations}(既定と違う設定で起動したテストも同じ判定を使う)。
 */
final class AppRoutesTest extends TransactionalTestCase
{
    use FindsAppRouteViolations;

    public function test_every_app_route_passes_through_route_authorization(): void
    {
        $this->assertSame([], $this->routesWithoutAuthorization($this->routeResources()->appRoutes()));
    }

    public function test_every_app_route_name_follows_the_rules_and_starts_with_the_module_of_its_handler(): void
    {
        $this->assertSame([], $this->routesAgainstTheNameRules($this->routeResources()->appRoutes()));
    }

    public function test_the_check_finds_a_route_named_after_a_module_other_than_the_one_of_its_handler(): void
    {
        $routes = [
            Route::inertia('/test/menu-page', 'Menu/Home')->name('master.test_menu_page'),
            Route::get('/test/menu-controller', SitemapController::class)->name('authz.test_menu_controller'),
            Route::get('/test/closure', fn () => 'ok')->name('menu.test_closure'),
            // 先頭の区切りは宣言に無いモジュールの名前(将来も宣言しない名前にする。宣言したモジュールの名前だと違反にならない)
            Route::redirect('/test/undeclared', '/')->name('undeclared.test_undeclared'),
            Route::inertia('/test/own-page', 'Menu/Home')->name('menu.test_own_page'),
            Route::get('/test/own-controller', SitemapController::class)->name('menu.test_own_controller'),
            Route::redirect('/test/moved', '/')->name('master.test_moved'),
            Route::view('/test/view', 'app')->name('authz.test_view'),
        ];

        $this->assertSame(['test/menu-page', 'test/menu-controller', 'test/closure', 'test/undeclared'], $this->routesAgainstTheNameRules($routes));
    }

    public function test_every_app_route_name_is_unique_among_the_app_routes(): void
    {
        $this->assertSame([], $this->duplicatedNames($this->routeResources()->appRoutes()));
    }

    public function test_the_check_finds_a_name_given_to_two_app_routes(): void
    {
        Route::get('/test/first', [SitemapController::class, '__invoke'])->name('menu.test_same');
        Route::get('/test/second', [SitemapController::class, '__invoke'])->name('menu.test_same');

        $this->assertSame(['menu.test_same'], $this->duplicatedNames($this->routeResources()->appRoutes()));
    }

    public function test_no_app_route_has_a_host_or_scheme_condition(): void
    {
        $this->assertSame([], $this->routesWithHostOrSchemeCondition($this->routeResources()->appRoutes()));
    }

    public function test_the_check_finds_a_route_with_a_host_or_scheme_condition(): void
    {
        $routes = [
            Route::domain('admin.example.test')->get('/test/host', SitemapController::class)->name('menu.test_host'),
            Route::get('/test/https', ['https', 'uses' => SitemapController::class])->name('menu.test_https'),
            Route::get('/test/http', ['http', 'uses' => SitemapController::class])->name('menu.test_http'),
            Route::get('/test/any', SitemapController::class)->name('menu.test_any'),
        ];

        $this->assertSame(['test/host', 'test/https', 'test/http'], $this->routesWithHostOrSchemeCondition($routes));
    }

    public function test_no_app_route_parameter_has_an_explicit_binding(): void
    {
        $this->assertSame([], $this->routesWithExplicitBinding($this->routeResources()->appRoutes()));
    }

    public function test_the_check_finds_a_route_whose_parameter_has_an_explicit_binding(): void
    {
        Route::bind('bound', fn (string $value) => $value);
        $routes = [
            Route::get('/test/bound/{bound}', SitemapController::class)->name('menu.test_bound'),
            Route::get('/test/free/{free}', SitemapController::class)->name('menu.test_free'),
        ];

        $this->assertSame(['test/bound/{bound}'], $this->routesWithExplicitBinding($routes));
    }

    public function test_the_check_finds_a_route_that_removes_route_authorization(): void
    {
        $removed = Route::middleware(['web', AuthorizeRoute::class])->get('/test/removed', fn () => 'ok')->name('menu.test_removed')->withoutMiddleware(AuthorizeRoute::class);
        $outside = Route::middleware('web')->get('/test/outside', fn () => 'ok')->name('menu.test_outside');

        $this->assertSame(['test/removed', 'test/outside'], $this->routesWithoutAuthorization([$removed, $outside]));
    }

    public function test_no_app_route_is_handled_by_a_closure(): void
    {
        $this->assertFalse(app()->routesAreCached());
        $this->assertSame([], $this->routesHandledByClosure($this->routeResources()->appRoutes()));
    }

    public function test_the_check_finds_a_route_handled_by_a_closure(): void
    {
        $route = Route::middleware(['web', AuthorizeRoute::class])->get('/test/closure', fn () => 'ok')->name('menu.test_closure');

        $this->assertSame(['test/closure'], $this->routesHandledByClosure([$route]));
    }

    public function test_the_health_check_and_package_routes_stay_outside_route_authorization(): void
    {
        $this->get('/up')->assertOk();
        $this->assertNotContains('up', array_map(fn (RoutingRoute $route) => $route->uri(), $this->routeResources()->appRoutes()));
    }

    public function test_does_not_register_the_file_serving_routes(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }
}

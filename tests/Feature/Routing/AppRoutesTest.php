<?php

namespace Tests\Feature\Routing;

use App\Enums\Authz\ServiceAction;
use App\Http\Controllers\Web\Menu\SitemapController;
use App\Http\Middleware\Authz\AuthorizeRoute;
use App\Support\RoutePlacement;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
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
 * 名前の規則に、置き場が http-services のルートどうしで同じ接頭辞の直下にルートとより長い接頭辞を混ぜないことを含む(ルートの束)。
 * あわせて、置き場の宣言が固定値の表のとおりで、ルートのキャッシュの後も残ること。
 * 判定は {@see FindsAppRouteViolations}(既定と違う設定で起動したテストも同じ判定を使う)。
 */
final class AppRoutesTest extends TransactionalTestCase
{
    use FindsAppRouteViolations;

    /**
     * 置き場が設定不可のセットのアプリのルートの固定値(既定の起動の 60 本。取り外せるモジュールの切替の 1 本は、そのモジュールのテストが確かめる)。
     * 並びは routes/web.php の順。
     */
    private const UNMANAGED_ROUTES = [
        'menu.home', 'menu.sitemap', 'account.logout', 'account.current-department', 'master.search', 'notice.index',
        'notice.latest', 'notice.read-all', 'notice.read', 'meeting.store', 'meeting.regenerate',
        'records.data.index', 'records.data.create', 'records.data.store', 'records.data.candidates',
        'records.data.show', 'records.data.edit', 'records.data.update', 'records.data.destroy',
        'records.data.attachments.store', 'records.data.attachments.destroy', 'records.data.attachments.show',
        'records.data.attachments.preview', 'records.data.comments.store', 'records.data.comments.candidates',
        'records.data.comments.destroy', 'records.data.revision-files.show', 'records.data.revision-files.preview',
        'workflow.home', 'workflow.flows.index', 'workflow.applications.create', 'workflow.applications.store',
        'workflow.drafts.store', 'workflow.drafts.edit', 'workflow.drafts.update', 'workflow.drafts.apply',
        'workflow.drafts.destroy', 'workflow.cases.index', 'workflow.cases.bulk-approve',
        'workflow.cases.bulk-confirm', 'workflow.user-candidates', 'workflow.target-values',
        'workflow.record-candidates', 'workflow.cases.show', 'workflow.cases.approve',
        'workflow.cases.approve-early', 'workflow.cases.reject', 'workflow.cases.send-back',
        'workflow.cases.pull-back', 'workflow.cases.assignee-candidates', 'workflow.cases.withdraw',
        'workflow.cases.hold', 'workflow.cases.release', 'workflow.cases.transfer', 'workflow.cases.confirm',
        'workflow.reapplications.create', 'workflow.reapplications.store', 'workflow.proxies.index',
        'workflow.proxies.store', 'workflow.proxies.destroy',
    ];

    /** 置き場が http-services のアプリのルート(53 本。並びは routes/web.php の順) */
    private const MANAGED_ROUTES = [
        'authz.settings.show', 'authz.settings.update-cell', 'authz.settings.store-subject',
        'authz.settings.destroy-subject', 'authz.settings.subject-candidates', 'master.organization-chart.show',
        'records.admin.comments.destroy', 'records.admin.modules.index', 'records.admin.modules.create',
        'records.admin.modules.store', 'records.admin.modules.show', 'records.admin.modules.edit',
        'records.admin.categories.reorder-uncategorized', 'records.admin.modules.update',
        'records.admin.categories.update-module-categories', 'records.admin.modules.create-field',
        'records.admin.modules.store-field', 'records.admin.modules.reorder-fields',
        'records.admin.modules.edit-field', 'records.admin.modules.update-field',
        'records.admin.modules.destroy-field', 'records.admin.modules.store-heading',
        'records.admin.modules.update-heading', 'records.admin.modules.destroy-heading',
        'records.admin.modules.update-related-columns', 'records.admin.modules.store-rule',
        'records.admin.modules.update-rule', 'records.admin.modules.destroy-rule', 'records.admin.categories.index',
        'records.admin.categories.store', 'records.admin.categories.reorder', 'records.admin.categories.update',
        'records.admin.categories.destroy', 'records.admin.categories.reorder-modules',
        'records.admin.categories.modules', 'workflow.admin.proxies.index', 'workflow.admin.proxies.store',
        'workflow.admin.proxies.destroy', 'workflow.admin.cases.index', 'workflow.admin.cases.retarget',
        'workflow.admin.cases.reexpand', 'workflow.admin.cases.move', 'workflow.admin.cases.assign',
        'workflow.admin.cases.destroy', 'workflow.admin.flows.index', 'workflow.admin.flows.show',
        'workflow.admin.flows.update-settings', 'workflow.admin.flows.update-referrers',
        'workflow.admin.flows.store-version', 'workflow.admin.flows.disable-version',
        'workflow.admin.flows.enable-version', 'workflow.admin.flows.update-node',
        'workflow.admin.flows.subject-candidates',
    ];

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
            Route::redirect('/test/moved', '/')->name('notice.test_moved'),
            Route::view('/test/view', 'app')->name('notice.test_view'),
        ];

        $this->assertSame(['test/menu-page', 'test/menu-controller', 'test/closure', 'test/undeclared'], $this->routesAgainstTheNameRules($routes));
    }

    /**
     * 置き場が http-services のルートどうしで、同じ接頭辞の直下にルートとより長い接頭辞を混ぜない(混ざった接頭辞の下のルートはどちらの側も違反)。
     * 置き場が設定不可のセットのルートは混ぜてよく、http-services のルートと比べもしない。
     */
    public function test_the_check_finds_routes_under_a_prefix_mixing_routes_and_longer_prefixes(): void
    {
        $routes = [
            Route::get('/test/mixed', SitemapController::class)->name('menu.test_mixed.show'),
            Route::get('/test/mixed/child', SitemapController::class)->name('menu.test_mixed.child.show'),
            Route::get('/test/bundle', SitemapController::class)->name('menu.test_bundle.show'),
            Route::get('/test/bundle/edit', SitemapController::class)->name('menu.test_bundle.edit'),
        ];
        Route::metadata(RoutePlacement::UNMANAGED)->group(function () use (&$routes): void {
            $routes[] = Route::get('/test/free', SitemapController::class)->name('menu.test_free.show');
            $routes[] = Route::get('/test/free/child', SitemapController::class)->name('menu.test_free.child.show');
            $routes[] = Route::get('/test/bundle/child', SitemapController::class)->name('menu.test_bundle.child.show');
        });

        $this->assertSame(['test/mixed', 'test/mixed/child'], $this->routesAgainstTheNameRules($routes));
    }

    /**
     * 置き場の宣言(docs の 設計/メニュー/認可との結び付け.md 2.3 の置き場): 固定値の 113 本(設定不可 60・http-services 53)が宣言のとおりで、
     * ほかのアプリのルートは無い。
     */
    public function test_every_app_route_is_declared_in_the_set_of_the_table(): void
    {
        $this->assertSame($this->expectedSets(), $this->setsOfAppRoutes());
    }

    /**
     * ルートのキャッシュ(route:cache)の後も、宣言の置き場が残る(宣言はルートの action に入る)。キャッシュのファイルは一時ファイルに書き、
     * このテストの中で読み込んで消す(作業木の bootstrap/cache には書かない)。
     */
    public function test_every_app_route_keeps_its_set_after_the_routes_are_cached(): void
    {
        $path = sys_get_temp_dir().'/app-routes-cache-'.bin2hex(random_bytes(6)).'.php';
        putenv("APP_ROUTES_CACHE={$path}");

        try {
            $this->assertSame($path, $this->app->getCachedRoutesPath());
            $this->assertSame(0, Artisan::call('route:cache'));
            require $path;

            $this->assertSame($this->expectedSets(), $this->setsOfAppRoutes());
        } finally {
            putenv('APP_ROUTES_CACHE');

            if (is_file($path)) {
                unlink($path);
            }
        }
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

    /**
     * 固定値の表の、ルートの名前 → 置き場(名前の順)。
     *
     * @return array<string, string>
     */
    private function expectedSets(): array
    {
        $sets = [
            ...array_fill_keys(self::UNMANAGED_ROUTES, ServiceAction::UNMANAGED_SET_CODE),
            ...array_fill_keys(self::MANAGED_ROUTES, ServiceAction::SET_CODE),
        ];
        ksort($sets);

        return $sets;
    }

    /**
     * アプリのルートの、名前 → 宣言の置き場(名前の順)。
     *
     * @return array<string, string>
     */
    private function setsOfAppRoutes(): array
    {
        $sets = [];

        foreach ($this->routeResources()->appRoutes() as $route) {
            $sets[(string) $route->getName()] = $this->routeResources()->setOf((string) $route->getName());
        }

        ksort($sets);

        return $sets;
    }
}

<?php

namespace Tests\Feature\Demo;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Providers\DemoServiceProvider;
use App\Queries\Account\UserMenuSections;
use App\Queries\Demo\SwitchUserMenuSection;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Reset\Api\ResetSeeder;
use App\Queries\Reset\InitialDataSeeders;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * オフのとき成り立つこと(docs の 設計/デモユーザー切替/デモの取り付け.md 6.4 のオフの 1・2 と 6.3 の DemoSeeder、6.5 の塞ぎ方)。
 * 既定の起動(phpunit.xml が DEMO_ENABLED を false に固定)のまま確かめる。DemoSeeder を流していないときの初期データの規則は、
 * main の AuthorizationSeedersTest が既定の起動(オフ)で確かめている。
 * 今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class DemoDisabledTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_registers_no_switch_route(): void
    {
        $routes = app(Router::class)->getRoutes()->getRoutes();

        $this->assertFalse(config('demo.enabled'));
        $this->assertSame([], array_values(array_filter($routes, fn (RoutingRoute $route) => str_starts_with((string) $route->getName(), 'demo.')
            || str_starts_with(ltrim((string) $route->getControllerClass(), '\\'), 'App\Http\Controllers\Web\Demo\\'))));
    }

    public function test_the_switch_url_is_not_found(): void
    {
        $this->post('/demo/switch-user', ['user_id' => $this->userId('u0001')])->assertNotFound();
        $this->assertGuest();
    }

    public function test_shares_no_user_menu_section_and_tags_no_section_of_its_own(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('userMenuSections', []));
        $this->assertSame([], array_values(array_filter(
            iterator_to_array($this->app->tagged(UserMenuSections::TAG), false),
            fn (object $section) => $section instanceof SwitchUserMenuSection,
        )));
    }

    /**
     * ホームの props sections に demo の節(code が demo- で始まる)が無く、タグ HomeSections::TAG に demo の実装が無い(6.4 のオフの 2)。
     */
    public function test_the_home_has_no_demo_section_and_tags_no_home_section_of_its_own(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('sections', []));
        $this->assertSame([], array_values(array_filter(
            iterator_to_array($this->app->tagged(HomeSections::TAG), false),
            fn (object $section) => str_starts_with($section::class, 'App\Queries\Demo\\'),
        )));
    }

    /**
     * 毎日の初期化の seeder の名前の口のタグに demo の実装が無い(6.4 のオフの 2。オフの間の初期化は切替の初期データを入れない)。
     */
    public function test_tags_no_reset_seeder_of_its_own(): void
    {
        $this->assertSame([], array_values(array_filter(
            iterator_to_array($this->app->tagged(ResetSeeder::TAG), false),
            fn (object $seeder) => str_starts_with($seeder::class, 'App\Queries\Demo\\'),
        )));
        $base = ['--force' => true, '--no-interaction' => true];
        $this->assertSame([$base], app(InitialDataSeeders::class)->seedArguments());
    }

    public function test_a_switch_route_left_over_is_not_found_while_disabled(): void
    {
        $this->registerSwitchRouteAsIfCached();
        $this->seedSwitchRouteAuthorization();

        $this->post('/demo/switch-user', ['user_id' => $this->userId('u0001')])->assertNotFound();
        $this->assertGuest();
    }

    public function test_the_seeder_refuses_to_write_while_disabled(): void
    {
        $groups = AuthzResourceGroup::query()->count();

        $this->assertThrows(fn () => app(DemoSeeder::class)->run(), RuntimeException::class);
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    /**
     * オンで作ったルートのキャッシュが残った状態に当たる: 設定がオフのまま、プロバイダのルートを読み込む処理を呼び、
     * 後から付いた名前と処理を引けるようにルートの名前と処理の表を作り直す。
     */
    private function registerSwitchRouteAsIfCached(): void
    {
        (new DemoServiceProvider($this->app))->loadSwitchUserRoutes();
        app(Router::class)->getRoutes()->refreshNameLookups();
        app(Router::class)->getRoutes()->refreshActionLookups();
    }

    /**
     * 切替のルートのリソースとポリシー(ルートの認可を通すため)を DemoSeeder で入れる。DemoSeeder はオフでは書かないので、流す間だけ
     * 設定をオンにして戻す(ルートの登録は起動の時点で済んでいるので、この間の設定はルートと節の登録に影響しない)。
     */
    private function seedSwitchRouteAuthorization(): void
    {
        config(['demo.enabled' => true]);
        $this->seed(DemoSeeder::class);
        config(['demo.enabled' => false]);
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}

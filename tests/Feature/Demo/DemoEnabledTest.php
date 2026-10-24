<?php

namespace Tests\Feature\Demo;

use App\Http\Controllers\Web\Demo\SwitchUserController;
use App\Models\Master\User;
use App\Queries\Account\UserMenuSections;
use App\Queries\Demo\DemoResetSeeder;
use App\Queries\Demo\LeadHomeSection;
use App\Queries\Demo\PeopleHomeSection;
use App\Queries\Demo\SwitchUserMenuSection;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Reset\Api\ResetSeeder;
use App\Queries\Reset\InitialDataSeeders;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FindsAppRouteViolations;
use Tests\Support\TransactionalTestCase;

/**
 * オンで現れるもの(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3・6.4): 切替のルート、利用者メニューの「利用者の切替」の節
 * (項目は一覧のうち今日有効なもの。部分再読み込みにも入る)、ゲストのホームのあいさつ欄の一文と役割カード(切り替えた後は無い)、ルートの規則と初期データの規則(main の検査と同じ判定
 * {@see FindsAppRouteViolations} で、初期データと `DemoSeeder` の後に)。
 * オンで起動し({@see EnablesDemoBeforeBoot})、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class DemoEnabledTest extends TransactionalTestCase
{
    use EnablesDemoBeforeBoot;
    use FindsAppRouteViolations;

    private const HEADING = '利用者の切替';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->seed(DemoSeeder::class);
    }

    public function test_registers_the_switch_route_and_tags_its_user_menu_section(): void
    {
        $route = app(Router::class)->getRoutes()->getByName('demo.switch-user');

        $this->assertSame(['demo/switch-user', ['POST'], SwitchUserController::class], [$route?->uri(), $route?->methods(), ltrim((string) $route?->getControllerClass(), '\\')]);
        $this->assertCount(1, array_filter(
            iterator_to_array($this->app->tagged(UserMenuSections::TAG), false),
            fn (object $section) => $section instanceof SwitchUserMenuSection,
        ));
    }

    public function test_the_route_rules_hold_with_the_switch_route(): void
    {
        $routes = $this->routeResources()->appRoutes();

        $this->assertContains('demo.switch-user', $this->routeNames());
        $this->assertSame([], $this->routesWithoutAuthorization($routes));
        $this->assertSame([], $this->routesAgainstTheNameRules($routes));
        $this->assertSame([], $this->duplicatedNames($routes));
        $this->assertSame([], $this->routesWithHostOrSchemeCondition($routes));
        $this->assertSame([], $this->routesWithExplicitBinding($routes));
        $this->assertSame([], $this->routesHandledByClosure($routes));
        $this->assertSame([], $this->routesWithoutTheirResource($routes));
    }

    public function test_shares_the_section_with_the_candidates_effective_today_in_the_order_of_the_users(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('userMenuSections', [[
            'heading' => self::HEADING,
            'items' => $this->expectedItems([
                ['u0001', '佐々木 陽太', '申請者'],
                ['u0002', '中村 美咲', '上長(課長)'],
                ['u0003', '高橋 誠', '部長'],
                ['u0004', '伊藤 涼子', 'システム管理者'],
                ['u0009', '吉田 達也', '兼務(総務・人事)'],
                ['u0010', '山田 恵', '経理'],
            ]),
        ]]));
    }

    public function test_leaves_out_a_listed_user_who_is_not_effective_today(): void
    {
        config(['demo.users' => ['u0014' => ['role' => '退職者', 'can' => '退職者の文'], 'u0001' => ['role' => '申請者', 'can' => '申請者の文']]]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('userMenuSections.0.items', $this->expectedItems([['u0001', '佐々木 陽太', '申請者']]))
            ->where('sections.1.items', [$this->expectedCard('u0001', '佐々木 陽太', '東京営業課', '申請者', '申請者の文')])
        );
    }

    /**
     * ホームの節(6.3): タグの最後に、あいさつ欄の一文・役割カードの順で並ぶ。
     */
    public function test_tags_its_home_sections_after_the_main_sections(): void
    {
        $registered = array_map(fn (object $section) => $section::class, iterator_to_array($this->app->tagged(HomeSections::TAG), false));

        $this->assertSame([LeadHomeSection::class, PeopleHomeSection::class], array_slice($registered, -2));
    }

    /**
     * 毎日の初期化の seeder の名前の口(6.3 の 5): タグに DemoResetSeeder が 1 つあり、返す名前を db:seed --class に渡すと DemoSeeder を指す
     * (名前空間を省いた名前は Database\Seeders の下 = SeedCommand の解決)。
     */
    public function test_tags_its_reset_seeder_that_names_the_seeder(): void
    {
        $ours = array_values(array_filter(
            iterator_to_array($this->app->tagged(ResetSeeder::TAG), false),
            fn (object $seeder) => $seeder instanceof DemoResetSeeder,
        ));

        $this->assertCount(1, $ours);
        $this->assertSame(DemoSeeder::class, 'Database\Seeders\\'.$ours[0]->seederName());
    }

    /** Reset の初期データの並び(6.4 のオンの行): DatabaseSeeder(名前を付けない db:seed)→ DemoSeeder */
    public function test_the_initial_data_of_the_reset_is_the_database_seeder_then_the_demo_seeder(): void
    {
        $base = ['--force' => true, '--no-interaction' => true];

        $expected = [$base, [...$base, '--class' => 'DemoSeeder']];

        $this->assertSame($expected, app(InitialDataSeeders::class)->seedArguments());
    }

    /**
     * ゲストのホームに、あいさつ欄の一文と主ボタン(役割カードへのページ内リンク)と、今日有効な候補の役割カード(所属・役どころ・試せることは一覧の値)が出る。
     */
    public function test_a_guest_home_has_the_lead_and_the_cards_of_the_candidates(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('sections', [
            [
                'code' => 'demo-lead',
                'kind' => 'lead',
                'area' => 'hero',
                'text' => '申請 → 課長 → 部長の承認の流れを、利用者を切り替えながら試せます。',
                'action' => ['label' => '利用者を選択して試す', 'url' => '#demo-people', 'method' => 'get', 'data' => []],
            ],
            [
                'code' => 'demo-people',
                'kind' => 'people',
                'area' => 'top',
                'heading' => '試せる利用者',
                'noteLabel' => 'この人で試せること',
                'items' => [
                    $this->expectedCard('u0001', '佐々木 陽太', '東京営業課', '申請者', '申請し、承認の進み具合を確認できます。'),
                    $this->expectedCard('u0002', '中村 美咲', '東京営業課 課長', '上長(課長)', '部下の申請を 1 段目で承認・差戻しできます。'),
                    $this->expectedCard('u0003', '高橋 誠', '第一営業部 部長', '部長', '課長が承認した申請を、2 段目で承認・否認できます。'),
                    $this->expectedCard('u0004', '伊藤 涼子', '経営企画室 主任', 'システム管理者', '認可設定と、ロールによるメニューの表示の違いを確認できます。'),
                    $this->expectedCard('u0009', '吉田 達也', '総務部 部長', '兼務(総務・人事)', '主所属(総務部)と兼務先(人事部)を、カレント組織として切り替えられます。'),
                    $this->expectedCard('u0010', '山田 恵', '経理部 部長', '経理', '経理部の参照者として、プロジェクト計画の申請を参照できます。'),
                ],
            ],
        ]));
    }

    /**
     * 切り替えた後(今の利用者がいる)のホームには、デモの節が無い。
     */
    public function test_a_user_home_has_no_demo_sections(): void
    {
        $this->post('/demo/switch-user', ['user_id' => User::query()->where('code', 'u0001')->sole()->id])->assertRedirect('/');

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where(
            'sections',
            fn (Collection $sections) => $sections->every(fn (array $section) => ! str_starts_with($section['code'], 'demo-')),
        ));
    }

    /**
     * 候補が 1 人もいなければ、一文も役割カードも出さない(カードの無い主ボタンを出さない)。
     */
    public function test_a_guest_home_has_no_demo_sections_without_candidates(): void
    {
        config(['demo.users' => ['u0014' => ['role' => '退職者', 'can' => '退職者の文']]]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('sections', []));
    }

    public function test_shares_the_section_also_on_a_partial_reload_of_other_props(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('globalNavigation', fn (Assert $reload) => $reload
                ->where('userMenuSections.0.heading', self::HEADING)
                ->count('userMenuSections.0.items', 6)
            )
        );
    }

    /**
     * @param  list<array{string, string, string}>  $users  コード・氏名・役どころ
     * @return list<array<string, mixed>>
     */
    private function expectedItems(array $users): array
    {
        return array_map(fn (array $user) => [
            'code' => $user[0],
            'name' => $user[1],
            'note' => $user[2],
            'url' => route('demo.switch-user'),
            'method' => 'post',
            'data' => ['user_id' => User::query()->where('code', $user[0])->sole()->id],
        ], $users);
    }

    /**
     * 役割カードの項目 1 つ(送り先は利用者メニューの項目と同じ)。
     *
     * @return array<string, mixed>
     */
    private function expectedCard(string $code, string $name, string $affiliation, string $role, string $note): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'affiliation' => $affiliation,
            'role' => $role,
            'note' => $note,
            'action' => ['label' => 'この人で始める', 'url' => route('demo.switch-user'), 'method' => 'post', 'data' => ['user_id' => User::query()->where('code', $code)->sole()->id]],
        ];
    }

    /**
     * アプリのルートの名前。
     *
     * @return list<string|null>
     */
    private function routeNames(): array
    {
        return array_map(fn (RoutingRoute $route) => $route->getName(), $this->routeResources()->appRoutes());
    }
}

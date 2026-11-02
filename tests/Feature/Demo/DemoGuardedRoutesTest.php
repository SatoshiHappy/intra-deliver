<?php

namespace Tests\Feature\Demo;

use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Queries\Authz\Api\GuardedRoute;
use App\Queries\Authz\Api\GuardedRoutes;
use App\Queries\Demo\DemoGuardedRoutes;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * オンのとき、認可の編集の守るルート(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節・8 節の守りの 2): ホーム(menu.home)と
 * 切替(demo.switch-user)を守るルートに登録する。2 つのリソースは設定不可のセットにあり、そのグループのセルの切替は格子の行でないので 422
 * (守りの口の評価そのものは Authz の AuthzEditGuardTest が偽物の実装で確かめる)。登録しないときは {@see DemoGuardedRoutesDisabledTest}。
 * オンで起動し、初期データと DemoSeeder を入れ、今日を 2026-10-10 に固定して u0004 で送る。
 */
final class DemoGuardedRoutesTest extends TransactionalTestCase
{
    use EnablesDemoBeforeBoot;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->seed(DemoSeeder::class);
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    public function test_tags_the_guarded_routes_of_the_home_and_the_switch(): void
    {
        $implementations = array_values(array_filter(
            iterator_to_array($this->app->tagged(GuardedRoutes::TAG), false),
            fn (object $guardedRoutes) => $guardedRoutes instanceof DemoGuardedRoutes,
        ));

        $this->assertCount(1, $implementations);
        $names = array_map(fn (GuardedRoute $route) => $route->routeName, $implementations[0]->routes());
        $this->assertSame(['menu.home', 'demo.switch-user'], $names);
    }

    /**
     * ホームと切替のリソースは設定不可のセットにあり、そのグループのセルの切替は格子の行でないので 422(守りの前に拒む)で、ゲストの許可が残る。
     */
    #[TestWith(['http-services-unmanaged.menu.home', 'none'])]
    #[TestWith(['http-services-unmanaged.menu.home', 'deny'])]
    #[TestWith(['http-services-unmanaged.demo.switch-user', 'none'])]
    #[TestWith(['http-services-unmanaged.demo.switch-user', 'deny'])]
    public function test_the_guest_cell_of_the_home_or_the_switch_is_not_a_row(string $groupCode, string $to): void
    {
        $this->changeGuestCell($groupCode, 'permit', $to)->assertSessionHasErrors('resource_group_id');

        $this->assertSame(PolicyEffect::Permit, AuthzPolicy::query()->where([
            'authz_resource_group_id' => AuthzResourceGroup::query()->where('code', $groupCode)->sole()->id,
            'authz_subject_group_id' => $this->guestId(),
        ])->sole()->effect);
    }

    /**
     * 守るルートを登録していても、ゲストがホームと切替を実行できたままの変更(束「共通マスタ」のゲストの許可を外す)は通る。
     */
    public function test_changing_another_cell_of_the_guest_passes(): void
    {
        $this->changeGuestCell('http-services.master', 'permit', 'none')->assertSessionHasNoErrors();
    }

    /**
     * @return TestResponse<Response>
     */
    private function changeGuestCell(string $groupCode, string $from, string $to): TestResponse
    {
        return $this->from('/authz/overview')->put('/authz/editor/cells', [
            'resource_group_id' => AuthzResourceGroup::query()->where('code', $groupCode)->sole()->id,
            'subject_group_id' => $this->guestId(),
            'type' => 'service',
            'action' => 'execute',
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * ゲストユーザの列(初期データの seeder と同じ引き方)。
     */
    private function guestId(): int
    {
        return app(FindOrCreateSingleConditionSubjectGroup::class)
            ->execute(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous), [])->id;
    }
}

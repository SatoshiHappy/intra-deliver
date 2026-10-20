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
use App\Queries\Authz\AuthzEditGuard;
use App\Queries\Demo\DemoGuardedRoutes;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * オンのとき、認可の編集の守るルート(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節・8 節の守りの 2): ゲストの列から、
 * ホーム(menu.home)か切替(demo.switch-user)の execute を外す変更は 422 で巻き戻る。登録しないときに同じ変更が通ることは
 * {@see DemoGuardedRoutesDisabledTest}。オンで起動し、初期データと DemoSeeder を入れ、今日を 2026-10-10 に固定して u0004 で送る。
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

    #[TestWith(['http-services.menu.home', 'none'])]
    #[TestWith(['http-services.menu.home', 'deny'])]
    #[TestWith(['http-services.demo.switch-user', 'none'])]
    #[TestWith(['http-services.demo.switch-user', 'deny'])]
    public function test_taking_the_execute_from_the_guest_is_rolled_back(string $groupCode, string $to): void
    {
        $this->changeGuestCell($groupCode, 'permit', $to)
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);

        $this->assertSame(PolicyEffect::Permit, AuthzPolicy::query()->where([
            'authz_resource_group_id' => AuthzResourceGroup::query()->where('code', $groupCode)->sole()->id,
            'authz_subject_group_id' => $this->guestId(),
        ])->sole()->effect);
    }

    public function test_changing_another_cell_of_the_guest_passes(): void
    {
        $this->changeGuestCell('http-services.menu.sitemap', 'permit', 'none')->assertSessionHasNoErrors();
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

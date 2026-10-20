<?php

namespace Tests\Feature\Demo;

use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\MetaSubject;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Providers\DemoServiceProvider;
use App\Queries\Authz\Api\GuardedRoutes;
use App\Queries\Demo\DemoGuardedRoutes;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * オフのとき(守るルートの口を登録しないとき)、{@see DemoGuardedRoutesTest} で巻き戻る変更が通る(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md
 * 4 節の「取り外されている」と 8 節の守りの 2)。切替のルートのリソースとポリシーは、オンで作ったルートのキャッシュが残った状態に当てて入れる
 * ({@see DemoDisabledTest} と同じ作り方)。既定の起動のまま、今日を 2026-10-10 に固定して u0004 で送る。
 */
final class DemoGuardedRoutesDisabledTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    public function test_tags_no_guarded_routes_of_its_own(): void
    {
        $this->assertSame([], array_values(array_filter(
            iterator_to_array($this->app->tagged(GuardedRoutes::TAG), false),
            fn (object $guardedRoutes) => $guardedRoutes instanceof DemoGuardedRoutes,
        )));
    }

    #[TestWith(['http-services.menu.home'])]
    #[TestWith(['http-services.demo.switch-user'])]
    public function test_taking_the_execute_from_the_guest_passes(string $groupCode): void
    {
        $this->seedSwitchRouteAsIfCached();
        $guest = app(FindOrCreateSingleConditionSubjectGroup::class)
            ->execute(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous), [])->id;
        $groupId = AuthzResourceGroup::query()->where('code', $groupCode)->sole()->id;

        $this->from('/authz/overview')->put('/authz/editor/cells', [
            'resource_group_id' => $groupId,
            'subject_group_id' => $guest,
            'type' => 'service',
            'action' => 'execute',
            'from' => 'permit',
            'to' => 'none',
        ])->assertSessionHasNoErrors();

        $remaining = AuthzPolicy::query()
            ->where(['authz_resource_group_id' => $groupId, 'authz_subject_group_id' => $guest]);
        $this->assertFalse($remaining->exists());
    }

    /**
     * 切替のルートを読み込み(ルートの名前と処理の表を作り直す)、流す間だけ設定をオンにして DemoSeeder でリソースとポリシーを入れる。
     */
    private function seedSwitchRouteAsIfCached(): void
    {
        (new DemoServiceProvider($this->app))->loadSwitchUserRoutes();
        app(Router::class)->getRoutes()->refreshNameLookups();
        app(Router::class)->getRoutes()->refreshActionLookups();
        config(['demo.enabled' => true]);
        $this->seed(DemoSeeder::class);
        config(['demo.enabled' => false]);
    }
}

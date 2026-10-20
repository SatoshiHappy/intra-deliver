<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Queries\Authz\Api\GuardedRoute;
use App\Queries\Authz\Api\GuardedRoutes;
use App\Queries\Authz\AuthzEditGuard;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * セルの切替の守り(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節・8 節の守り): 操作している人が認可設定を開くと保存するの両方を
 * 失う変更は 422 で巻き戻る。ほかの人の列を変えるのは通る。守るルートの口は偽物の実装で確かめる(実際の実装の検査は、その持ち物のテスト)。
 * 列の削除の守りは {@see AuthzSubjectControllerTest}。初期データ(システム管理者 = u0004)を入れ、今日を 2026-10-10 に固定する。
 */
final class AuthzEditGuardTest extends TransactionalTestCase
{
    use EditsAuthz;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->actingAs($this->user('u0004'));
    }

    public function test_denying_the_head_of_the_screens_to_the_administrator_role_is_rolled_back(): void
    {
        $this->changeCell('http-services', $this->administratorColumn(), 'permit', 'deny')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);

        $this->assertSame('permit', $this->directSetting('http-services', $this->administratorColumn()));
    }

    /**
     * 開く(authz.overview)と保存する(authz.editor.cells.update)の両方を求める(片方だけを禁止しても 422)。
     */
    public function test_denying_either_opening_or_saving_is_rolled_back(): void
    {
        foreach (['http-services.authz.overview', 'http-services.authz.editor.cells.update'] as $code) {
            $this->changeCell($code, $this->administratorColumn(), 'none', 'deny')
                ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);

            $this->assertSame('none', $this->directSetting($code, $this->administratorColumn()));
        }
    }

    public function test_changing_the_column_of_someone_else_passes(): void
    {
        $this->changeCell('http-services.authz.overview', $this->authenticatedColumn(), 'none', 'deny')
            ->assertSessionHasNoErrors();
        $this->changeCell('http-services.authz.editor', $this->userColumn('u0001'), 'none', 'permit')
            ->assertSessionHasNoErrors();

        $this->assertSame('deny', $this->directSetting('http-services.authz.overview', $this->authenticatedColumn()));
    }

    public function test_a_registered_guarded_route_must_stay_executable_by_the_guest(): void
    {
        $this->registerGuardedRoute('menu.sitemap');

        $this->changeCell('http-services.menu.sitemap', $this->guestColumn(), 'permit', 'none')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);
        $this->changeCell('http-services.menu.sitemap', $this->guestColumn(), 'permit', 'deny')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);

        $this->assertSame('permit', $this->directSetting('http-services.menu.sitemap', $this->guestColumn()));
    }

    public function test_without_a_guarded_route_the_same_change_passes(): void
    {
        $this->changeCell('http-services.menu.sitemap', $this->guestColumn(), 'permit', 'none')
            ->assertSessionHasNoErrors();

        $this->assertSame('none', $this->directSetting('http-services.menu.sitemap', $this->guestColumn()));
    }

    public function test_the_guarded_route_is_judged_for_the_guest_not_for_the_operator(): void
    {
        $this->registerGuardedRoute('authz.overview');

        $this->changeCell('http-services.master.search', $this->guestColumn(), 'permit', 'none')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);
        $this->place('http-services.authz.overview', $this->guestColumn(), PolicyEffect::Permit);
        $this->changeCell('http-services.master.search', $this->guestColumn(), 'permit', 'none')
            ->assertSessionHasNoErrors();
    }

    /**
     * 守るルートの口の偽物を、ゲストが実行できなければならないルート 1 つで登録する。
     */
    private function registerGuardedRoute(string $routeName): void
    {
        $this->app->instance('tests.guarded-routes', new readonly class($routeName) implements GuardedRoutes
        {
            public function __construct(private string $routeName) {}

            public function routes(): array
            {
                return [new GuardedRoute($this->routeName, GuardedRoute::GUEST)];
            }
        });
        $this->app->tag(['tests.guarded-routes'], GuardedRoutes::TAG);
    }
}

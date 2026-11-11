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

    /** ルートの束「認可設定」(開く・保存するのルートを含む) */
    private const AUTHZ_SETTINGS = 'http-services.authz.settings';

    /** ルートの束「組織図」(ゲストユーザ・認証済みユーザに直接の許可) */
    private const ORGANIZATION_CHART = 'http-services.master.organization-chart';

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
     * 開く(authz.settings.show)と保存する(authz.settings.update-cell)のルートは同じ束「認可設定」にあり、束を禁止にすると 422(8 節の守りの 1)。
     */
    public function test_denying_the_bundle_of_the_authz_settings_is_rolled_back(): void
    {
        $this->changeCell(self::AUTHZ_SETTINGS, $this->administratorColumn(), 'none', 'deny')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);

        $this->assertSame('none', $this->directSetting(self::AUTHZ_SETTINGS, $this->administratorColumn()));
    }

    public function test_changing_the_column_of_someone_else_passes(): void
    {
        $this->changeCell(self::AUTHZ_SETTINGS, $this->authenticatedColumn(), 'none', 'deny')
            ->assertSessionHasNoErrors();
        $this->changeCell(self::AUTHZ_SETTINGS, $this->userColumn('u0001'), 'none', 'permit')
            ->assertSessionHasNoErrors();

        $this->assertSame('deny', $this->directSetting(self::AUTHZ_SETTINGS, $this->authenticatedColumn()));
    }

    /**
     * 守るルートの口は、画面・処理のルート(組織図。ゲストの許可は束「組織図」)を返す偽物で確かめる(8 節の守りの 2)。
     */
    public function test_a_registered_guarded_route_must_stay_executable_by_the_guest(): void
    {
        $this->registerGuardedRoute('master.organization-chart.show');

        $this->changeCell(self::ORGANIZATION_CHART, $this->guestColumn(), 'permit', 'none')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);
        $this->changeCell(self::ORGANIZATION_CHART, $this->guestColumn(), 'permit', 'deny')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);

        $this->assertSame('permit', $this->directSetting(self::ORGANIZATION_CHART, $this->guestColumn()));
    }

    public function test_without_a_guarded_route_the_same_change_passes(): void
    {
        $this->changeCell(self::ORGANIZATION_CHART, $this->guestColumn(), 'permit', 'none')->assertSessionHasNoErrors();

        $this->assertSame('none', $this->directSetting(self::ORGANIZATION_CHART, $this->guestColumn()));
    }

    public function test_the_guarded_route_is_judged_for_the_guest_not_for_the_operator(): void
    {
        $this->registerGuardedRoute('authz.settings.show');

        $this->changeCell(self::ORGANIZATION_CHART, $this->guestColumn(), 'permit', 'none')
            ->assertSessionHasErrors(['cell' => AuthzEditGuard::VIOLATION_MESSAGE]);
        $this->place(self::AUTHZ_SETTINGS, $this->guestColumn(), PolicyEffect::Permit);
        $this->changeCell(self::ORGANIZATION_CHART, $this->guestColumn(), 'permit', 'none')->assertSessionHasNoErrors();
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

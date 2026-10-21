<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\RouteAuthorization;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * ルートの認可の判定の公開の口(判定はルートのミドルウェア AuthorizeRoute と同じ): 初期データのユーザー(システム管理者の u0004・
 * ほかのロールの u0001・u0007・ゲスト)で、口の答えが実際のルートの 403 の有無と一致する。リソースの無いルートの名前は偽(例外にしない)。
 */
final class RouteAuthorizationTest extends TransactionalTestCase
{
    private const TODAY = '2026-10-11';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
    }

    public function test_the_answer_matches_the_real_routes_403_for_every_user(): void
    {
        $requests = [
            'records.admin.comments.destroy' => fn () => $this->delete('/records/admin/comments/999999999'),
            'records.admin.modules.index' => fn () => $this->get('/records/admin/modules'),
        ];

        foreach ([null, 'u0001', 'u0004', 'u0007'] as $code) {
            $user = $code === null ? null : User::query()->where('code', $code)->sole();

            foreach ($requests as $route => $send) {
                $this->flushSession();

                if ($user === null) {
                    $this->app['auth']->forgetGuards();
                } else {
                    $this->actingAs($user);
                }

                $allowed = app(RouteAuthorization::class)->allows(
                    new Requester($user?->id, null),
                    $route,
                    CarbonImmutable::parse(self::TODAY),
                );

                $this->assertSame($allowed, $send()->getStatusCode() !== 403, "{$route} / ".($code ?? 'guest'));
            }
        }
    }

    public function test_only_the_system_administrator_may_use_the_comment_administration_route(): void
    {
        $allows = fn (?string $code) => app(RouteAuthorization::class)->allows(
            new Requester($code === null ? null : User::query()->where('code', $code)->sole()->id, null),
            'records.admin.comments.destroy',
            CarbonImmutable::parse(self::TODAY),
        );

        $this->assertTrue($allows('u0004'));
        $this->assertFalse($allows('u0001'));
        $this->assertFalse($allows('u0007'));
        $this->assertFalse($allows(null));
    }

    public function test_a_route_name_without_a_resource_is_not_allowed(): void
    {
        $requester = new Requester(User::query()->where('code', 'u0004')->sole()->id, null);

        $date = CarbonImmutable::parse(self::TODAY);

        $this->assertFalse(app(RouteAuthorization::class)->allows($requester, 'missing.route', $date));
    }
}

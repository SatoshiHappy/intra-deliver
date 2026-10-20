<?php

namespace Tests\Unit\Queries\Authz\Api;

use App\Queries\Authz\Api\GuardedRoute;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * 守るルート 1 つ(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節): 判定を求める者は guest だけで、ほかの値は呼び手の誤りとして拒む。
 */
final class GuardedRouteTest extends TestCase
{
    public function test_holds_the_route_name_and_the_guest(): void
    {
        $route = new GuardedRoute('menu.home', GuardedRoute::GUEST);

        $this->assertSame(['menu.home', 'guest'], [$route->routeName, $route->requester]);
    }

    #[TestWith(['authenticated'])]
    #[TestWith(['Guest'])]
    #[TestWith([''])]
    public function test_rejects_a_requester_other_than_the_guest(string $requester): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GuardedRoute('menu.home', $requester);
    }
}

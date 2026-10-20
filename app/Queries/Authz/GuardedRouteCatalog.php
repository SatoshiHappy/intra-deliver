<?php

namespace App\Queries\Authz;

use App\Queries\Authz\Api\GuardedRoute;
use App\Queries\Authz\Api\GuardedRoutes;
use Illuminate\Container\Attributes\Tag;

/**
 * 守るルートの全体 = タグ {@see GuardedRoutes::TAG} で束ねた実装の返すルートの和(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節)。
 * 束ねるのはここだけで、守りの評価({@see AuthzEditGuard})がこれを使う。実装が 1 つも登録されていなければ空(守るのは操作している人だけ)。
 */
class GuardedRouteCatalog
{
    /**
     * @param  iterable<GuardedRoutes>  $guardedRoutes
     */
    public function __construct(#[Tag(GuardedRoutes::TAG)] private iterable $guardedRoutes) {}

    /**
     * @return list<GuardedRoute>
     */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->guardedRoutes as $guardedRoutes) {
            $routes = [...$routes, ...$guardedRoutes->routes()];
        }

        return $routes;
    }
}

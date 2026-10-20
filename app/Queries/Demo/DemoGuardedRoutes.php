<?php

namespace App\Queries\Demo;

use App\Queries\Authz\Api\GuardedRoute;
use App\Queries\Authz\Api\GuardedRoutes;

/**
 * 認可の編集の守るルート(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節。口と形は main の {@see GuardedRoutes}): ゲストが切替に届くまでの
 * ホーム(役割カードと利用者メニューの切替がある)と切替のルートを、ゲストが実行できること(どちらかが外れると誰も管理者に戻れない)。
 * 起動(App\Providers の Demo のプロバイダ)が、設定がオンのときだけタグ {@see GuardedRoutes::TAG} に登録する。
 */
class DemoGuardedRoutes implements GuardedRoutes
{
    public function routes(): array
    {
        return [
            new GuardedRoute('menu.home', GuardedRoute::GUEST),
            new GuardedRoute('demo.switch-user', GuardedRoute::GUEST),
        ];
    }
}

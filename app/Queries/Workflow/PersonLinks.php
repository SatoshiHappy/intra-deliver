<?php

namespace App\Queries\Workflow;

use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\RouteAuthorization;
use App\Support\AccessContext;
use Closure;

/**
 * 名前の札の「…」のリンクを出すか(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1): 移り先のルートの execute を今日で判定する。
 * 札を出すページの要求ごとに 1 回だけ判定し、ページの props `links` で渡す(札ごとに判定しない)。鍵を取らない。
 *
 * @phpstan-type Links array{organizationChart: bool, authz: bool}
 */
class PersonLinks
{
    public function __construct(private RouteAuthorization $routes) {}

    /**
     * @return Links
     */
    public function of(AccessContext $context): array
    {
        $requester = Requester::fromAccessContext($context);

        return [
            'organizationChart' => $this->routes->allows($requester, 'master.organization-chart', $context->date),
            'authz' => $this->routes->allows($requester, 'authz.overview', $context->date),
        ];
    }

    /**
     * ページの props に置く形。部分の再読み込み(links を求めない要求)では判定しないよう、遅れて評価する(Inertia が求められたときだけ呼ぶ)。
     *
     * @return Closure(): Links
     */
    public function lazy(AccessContext $context): Closure
    {
        return fn () => $this->of($context);
    }
}

<?php

namespace App\Http\Middleware\Authz;

use App\Enums\Authz\ServiceAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\RouteResources;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ルートの認可(docs の 設計/メニュー/認可との結び付け.md 2.3 の既定で禁止の一様な規則)。
 * アプリのルート(routes/web.php と、デモユーザー切替がオンのときにそのプロバイダが読み込むルート)のすべてに付ける。付け忘れはルートの規則のテスト(AppRoutesTest と、デモユーザー切替がオンの状態の同じ検査)が止める。
 *
 * ルートの名前が無いとき・名前のリソース(service://…)が無いとき・判定が許可でないとき(禁止・閉塞)は 403。
 * 判定を求める者と基準日は {@see AccessContext}(判定の入力を決める 1 か所)から作る。
 */
class AuthorizeRoute
{
    public function __construct(
        private AuthorizationQuery $authorization,
        private RouteResources $routeResources,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();
        $context = AccessContext::of($request);

        $isAllowed = $name !== null && $this->authorization->allowsUri(
            Requester::fromAccessContext($context),
            $this->routeResources->uriOf($name),
            ServiceAction::Execute->value,
            $context->date,
        );

        abort_unless($isAllowed, Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}

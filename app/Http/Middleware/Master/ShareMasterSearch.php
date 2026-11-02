<?php

namespace App\Http\Middleware\Master;

use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\RouteAuthorization;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 見出しの共通マスタの検索欄を出すかを共有プロパティ masterSearch で全ページに渡す(docs の 設計/共通マスタ/検索と組織図.md 3.1・3.2):
 * visible = ルート master.organization-chart の execute を今日で判定した値(人のリンクの組織図 = App\Queries\Workflow\PersonLinks と同じ判定)。
 * 起動の配線が routes/web.php のグループに、ルートの認可の後に付ける。always にして部分再読み込みでも毎回判定する(骨格の共有と同じ)。
 */
class ShareMasterSearch
{
    public function __construct(private RouteAuthorization $routes) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('masterSearch', Inertia::always(function () use ($request) {
            $context = AccessContext::of($request);
            $requester = Requester::fromAccessContext($context);

            return ['visible' => $this->routes->allows($requester, 'master.organization-chart', $context->date)];
        }));

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware\Menu;

use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\MenuDisplayQuery;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * グローバルナビ(カテゴリ im_global_nav_pc の表示判定の結果)を、共有プロパティ globalNavigation で全ページに渡す
 * (docs の 設計/メニュー/表示.md 3.2)。起動の配線が routes/web.php のグループに、ルートの認可の後に付ける。
 *
 * クロージャで共有するので、判定は Inertia の応答を描くときだけ走る。always の props にするので、部分再読み込み(only で別の props だけを求める要求)
 * でも毎回判定して返す(判定の結果を要求をまたいで覚えない = 表示.md 3.1 の 8。普通の props は部分再読み込みで応答から除かれ、クライアントに前の
 * 判定の結果が残るため)。判定を求める者・基準日・ロケールは {@see AccessContext} から作る。
 */
class ShareGlobalNavigation
{
    public function __construct(private MenuDisplayQuery $menus) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('globalNavigation', Inertia::always(function () use ($request) {
            $context = AccessContext::of($request);

            return $this->menus->visibleGroups(MenuDisplayQuery::GLOBAL_NAVIGATION_CATEGORY, Requester::fromAccessContext($context), $context->date, $context->localeCode);
        }));

        return $next($request);
    }
}

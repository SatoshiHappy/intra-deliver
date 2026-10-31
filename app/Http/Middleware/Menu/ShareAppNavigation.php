<?php

namespace App\Http\Middleware\Menu;

use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\RouteAuthorization;
use App\Queries\Menu\CurrentApp;
use App\Queries\Menu\MenuDisplayQuery;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * 骨格の共有プロパティ appNavigation を全ページに渡す(docs の 設計/メニュー/表示.md 3.2 の「置き場」・3.3): ランチャーのアプリ launcher
 * (カテゴリ im_global_nav_pc の表示判定の結果)、歯車のアプリ adminMenu(admin_menu_pc の表示判定の結果)、今のアプリのグループの code currentApp と
 * 現在地のパス currentItemPath(3.3.2 = {@see CurrentApp}。無ければ null)、サイトマップを出すか showSitemap(ルート menu.sitemap の execute)。
 * 起動の配線が routes/web.php のグループに、ルートの認可の後に付ける。
 *
 * クロージャで共有するので、判定は Inertia の応答を描くときだけ走る。always の props にするので、部分再読み込み(only で別の props だけを求める要求)
 * でも毎回判定して返す(判定の結果を要求をまたいで覚えない = 表示.md 3.1 の 8。普通の props は部分再読み込みで応答から除かれ、クライアントに前の
 * 判定の結果が残るため)。判定を求める者・基準日・ロケールは {@see AccessContext} から作る。
 */
class ShareAppNavigation
{
    public function __construct(
        private MenuDisplayQuery $menus,
        private CurrentApp $currentApp,
        private RouteAuthorization $routes,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('appNavigation', Inertia::always(function () use ($request) {
            $context = AccessContext::of($request);
            $requester = Requester::fromAccessContext($context);
            [$date, $locale] = [$context->date, $context->localeCode];
            $groupsOf = fn (string $category) => $this->menus->visibleGroups($category, $requester, $date, $locale);
            $launcher = $groupsOf(MenuDisplayQuery::GLOBAL_NAVIGATION_CATEGORY);
            $adminMenu = $groupsOf(MenuDisplayQuery::ADMIN_MENU_CATEGORY);

            return [
                'launcher' => $launcher,
                'adminMenu' => $adminMenu,
                ...$this->currentApp->of($request->path(), [...$launcher, ...$adminMenu]),
                'showSitemap' => $this->routes->allows($requester, 'menu.sitemap', $date),
            ];
        }));

        return $next($request);
    }
}

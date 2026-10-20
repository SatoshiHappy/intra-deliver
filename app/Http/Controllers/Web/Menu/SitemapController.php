<?php

namespace App\Http\Controllers\Web\Menu;

use App\Http\Controllers\Controller;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\MenuDisplayQuery;
use App\Support\AccessContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * サイトマップ(docs の 設計/メニュー/表示.md 3.2): カテゴリ im_sitemap_pc の表示判定の結果を、ページ Menu/Sitemap の props groups で渡す。
 * 判定を求める者・基準日・ロケールは {@see AccessContext} から作る。
 */
class SitemapController extends Controller
{
    public function __construct(private MenuDisplayQuery $menus) {}

    public function __invoke(Request $request): Response
    {
        $context = AccessContext::of($request);

        return Inertia::render('Menu/Sitemap', [
            'groups' => $this->menus->visibleGroups(MenuDisplayQuery::SITEMAP_CATEGORY, Requester::fromAccessContext($context), $context->date, $context->localeCode),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Web\Menu;

use App\Http\Controllers\Controller;
use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 全体のホーム(docs の 設計/メニュー/ホーム.md 6.1・6.2): ページ Menu/Home に、基準日 d(today。YYYY-MM-DD)と、タグ {@see HomeSections::TAG} で
 * 登録した実装を登録の順に呼んだ null でない節(sections。登録が無ければ空の一覧 = あいさつ欄の見出しと日付だけのホーム)と、
 * アプリの名前 app = null(アプリのホームでない = ホーム.md 6.7)を渡す。
 * 判定の入力は {@see AccessContext} から作る。実装が投げた例外は握らない(実装は投げない契約。投げればホームが 500 になる)。
 *
 * @phpstan-import-type HomeLeadSection from HomeSections
 * @phpstan-import-type HomeCountersSection from HomeSections
 * @phpstan-import-type HomeTableSection from HomeSections
 * @phpstan-import-type HomeTilesSection from HomeSections
 * @phpstan-import-type HomeRoutesSection from HomeSections
 * @phpstan-import-type HomePeopleSection from HomeSections
 */
class HomeController extends Controller
{
    /**
     * @param  iterable<HomeSections>  $homeSections
     */
    public function __construct(#[Tag(HomeSections::TAG)] private iterable $homeSections) {}

    public function __invoke(Request $request): Response
    {
        $context = AccessContext::of($request);

        return Inertia::render('Menu/Home', [
            'today' => $context->date->toDateString(),
            'sections' => $this->sectionsOf($context),
            'app' => null,
        ]);
    }

    /**
     * @return list<HomeLeadSection|HomeCountersSection|HomeTableSection|HomeTilesSection|HomeRoutesSection|HomePeopleSection>
     */
    private function sectionsOf(AccessContext $context): array
    {
        $sections = [];

        foreach ($this->homeSections as $implementation) {
            $section = $implementation->section($context);

            if ($section !== null) {
                $sections[] = $section;
            }
        }

        return $sections;
    }
}

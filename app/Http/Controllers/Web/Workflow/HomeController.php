<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Workflow\WorkflowHomeSections;
use App\Support\AccessContext;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ワークフローのホーム(docs の 設計/メニュー/ホーム.md 6.7、節の中身 = 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節): ページ Menu/Home に、
 * 基準日 d(today。YYYY-MM-DD)と、タグ {@see WorkflowHomeSections::TAG} で登録した実装を登録の順に呼んだ null でない節(sections)と、
 * アプリの名前(app。ワークフローのアプリのグループの初期データの表示名と同じ語)を渡す。判定の入力は {@see AccessContext} から作る。
 * 実装が投げた例外は握らない(実装は投げない契約。投げればホームが 500 になる)。集める処理は入口ごとに持つ(ホーム.md 6.2)。
 */
class HomeController extends Controller
{
    /** アプリの名前(ワークフローのアプリのグループの表示名と同じ語。グループの表示名を読む口は足さない = ホーム.md 6.7) */
    private const APP_NAME = 'ワークフロー';

    /**
     * @param  iterable<HomeSections>  $homeSections
     */
    public function __construct(#[Tag(WorkflowHomeSections::TAG)] private iterable $homeSections) {}

    public function __invoke(Request $request): Response
    {
        $context = AccessContext::of($request);

        return Inertia::render('Menu/Home', [
            'today' => $context->date->toDateString(),
            'sections' => $this->sectionsOf($context),
            'app' => ['name' => self::APP_NAME],
        ]);
    }

    /**
     * 節(形 = {@see HomeSections::section()} の null でないもの)。
     *
     * @return list<array<string, mixed>>
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

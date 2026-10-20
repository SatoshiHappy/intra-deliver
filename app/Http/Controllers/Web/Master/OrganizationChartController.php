<?php

namespace App\Http\Controllers\Web\Master;

use App\Http\Controllers\Controller;
use App\Queries\Master\OrganizationChartQuery;
use App\Support\AccessContext;
use App\Support\QueryParameters;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 組織図(docs の 設計/共通マスタ/検索と組織図.md 1 節): ページ Master/OrganizationChart。クエリ department(選ぶ組織)・user(強調するユーザー)は
 * 整数として読めなければ指定なしと同じ。木 tree はクロージャで渡し、組織を押したときの部分再読み込み(selectedDepartment・members・
 * highlightedUserId)では作らない。基準日・ロケールは {@see AccessContext} から取る。判定はルートの認可だけ。
 */
class OrganizationChartController extends Controller
{
    public function __construct(private OrganizationChartQuery $chart) {}

    public function __invoke(Request $request): Response
    {
        $context = AccessContext::of($request);

        return Inertia::render('Master/OrganizationChart', [
            'tree' => fn () => $this->chart->treeOn($context->date, $context->localeCode),
            ...$this->chart->selectionOn(QueryParameters::integer($request, 'department'), $context->date, $context->localeCode),
            'highlightedUserId' => QueryParameters::integer($request, 'user'),
        ]);
    }
}

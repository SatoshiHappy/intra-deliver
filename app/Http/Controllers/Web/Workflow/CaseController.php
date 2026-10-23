<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Http\Controllers\Controller;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseListQuery;
use App\Support\AccessContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 案件一覧 Workflow/CaseIndex(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 2 節。クエリ tab・scope・page・q は読み取りが解釈し、
 * 解釈できない値は既定にする)と、案件の詳細 Workflow/CaseShow(設計/ワークフロー/画面.md 5 節。閲覧できない・一時保存の案件は 404)。
 */
class CaseController extends Controller
{
    public function __construct(
        private CaseListQuery $list,
        private CaseDetailQuery $detail,
    ) {}

    public function index(Request $request): Response
    {
        [$tab, $scope, $page, $q] = array_map(fn (string $key) => $request->query($key), ['tab', 'scope', 'page', 'q']);
        $list = $this->list->list(AccessContext::of($request), $tab, $scope, $page, $q);

        return Inertia::render('Workflow/CaseIndex', $list);
    }

    public function show(Request $request, string $case): Response
    {
        return Inertia::render('Workflow/CaseShow', $this->detail->show(AccessContext::of($request), (int) $case));
    }
}

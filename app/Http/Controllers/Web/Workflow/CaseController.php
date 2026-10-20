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
 * 案件一覧 Workflow/CaseIndex(docs の 設計/ワークフロー/画面.md 4 節。クエリ tab・scope・page は読み取りが解釈し、解釈できない値は既定にする)と、
 * 案件の詳細 Workflow/CaseShow(5 節。閲覧できない・一時保存の案件は 404)。
 */
class CaseController extends Controller
{
    public function __construct(
        private CaseListQuery $list,
        private CaseDetailQuery $detail,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Workflow/CaseIndex', $this->list->list(AccessContext::of($request), $request->query('tab'), $request->query('scope'), $request->query('page')));
    }

    public function show(Request $request, string $case): Response
    {
        return Inertia::render('Workflow/CaseShow', $this->detail->show(AccessContext::of($request), (int) $case));
    }
}

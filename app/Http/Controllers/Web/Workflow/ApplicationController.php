<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowApplicationRequest;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 新規の申請(docs の 設計/ワークフロー/画面.md 1.1・3 節): 画面 Workflow/Apply(mode new。フローが無い 404 → 申請できる人でない 403)と、申請の書き込み。
 * 成功は案件の詳細へのリダイレクト(POST は 302 のまま = 案件の操作/同時実行と検査.md 4 節)と成功のトースト。検証の失敗は元の画面へ戻りキーにエラーが付く。
 */
class ApplicationController extends Controller
{
    public function __construct(
        private ApplicationFormQuery $forms,
        private ApplyCase $applyCase,
    ) {}

    public function create(Request $request, string $flow): Response
    {
        return Inertia::render('Workflow/Apply', $this->forms->create(AccessContext::of($request), $flow, $request->query('base_date')));
    }

    public function store(FlowApplicationRequest $request, string $flow): RedirectResponse
    {
        $result = $this->applyCase->execute(AccessContext::of($request), $flow, $request->applicationInput(), $request->baseDate());
        Inertia::flash('toast', ['type' => 'success', 'message' => '申請しました']);

        return redirect()->route('workflow.cases.show', ['case' => $result->caseId]);
    }
}

<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\ReapplyCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\ReapplicationRequest;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 再申請(docs の 設計/ワークフロー/画面/README.md 1.1・画面/申請と案件.md 3 節)。
 * 再申請ができない状態・役割なら、画面を開かずに詳細へ移し {@see CaseOperationErrors::NOT_PERMITTED} を
 * トーストで出す(古いリンクから来た人を行き止まりにしない)。
 */
class ReapplicationController extends Controller
{
    public function __construct(
        private ApplicationFormQuery $forms,
        private ReapplyCase $reapplyCase,
    ) {}

    public function create(Request $request, string $case): Response|RedirectResponse
    {
        $form = $this->forms->reapply(AccessContext::of($request), (int) $case);

        if ($form === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => CaseOperationErrors::NOT_PERMITTED]);

            return redirect()->route('workflow.cases.show', ['case' => (int) $case]);
        }

        return Inertia::render('Workflow/Apply', $form);
    }

    public function store(ReapplicationRequest $request): RedirectResponse
    {
        $this->reapplyCase->execute(
            AccessContext::of($request), $request->caseId(), $request->revision(), $request->applicationInput(),
            $request->principalUserId(), $request->contact(),
        );
        Inertia::flash('toast', ['type' => 'success', 'message' => '再申請しました']);

        return redirect()->route('workflow.cases.show', ['case' => $request->caseId()]);
    }
}

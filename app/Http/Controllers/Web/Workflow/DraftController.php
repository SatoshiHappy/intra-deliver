<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DeleteDraft;
use App\Actions\Workflow\UpdateDraft;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\DraftApplicationRequest;
use App\Http\Requests\Workflow\DraftDeleteRequest;
use App\Http\Requests\Workflow\FlowApplicationRequest;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 一時保存(docs の 設計/ワークフロー/画面.md 1.1・3 節)。見えるが一時保存でない案件は、画面を開かずに詳細へ移し {@see CaseOperationErrors::NOT_PERMITTED} を
 * トーストで出す(古いリンクから来た人と、書き込みが 422 case で元の画面へ戻った人を行き止まりにしない)。
 * 入口で 303 を明示しない(PUT・DELETE は inertia-laravel の Middleware が 303 にする = 案件の操作/同時実行と検査.md 4 節)。
 */
class DraftController extends Controller
{
    public function __construct(
        private ApplicationFormQuery $forms,
        private CreateDraft $createDraft,
        private UpdateDraft $updateDraft,
        private ApplyCase $applyCase,
        private DeleteDraft $deleteDraft,
    ) {}

    public function store(FlowApplicationRequest $request, string $flow): RedirectResponse
    {
        $caseId = $this->createDraft->execute(AccessContext::of($request), $flow, $request->draftInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => '一時保存しました']);

        return redirect()->route('workflow.drafts.edit', ['case' => $caseId]);
    }

    public function edit(Request $request, string $case): Response|RedirectResponse
    {
        $form = $this->forms->draft(AccessContext::of($request), (int) $case, $request->query('base_date'));

        if ($form === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => CaseOperationErrors::NOT_PERMITTED]);

            return redirect()->route('workflow.cases.show', ['case' => (int) $case]);
        }

        return Inertia::render('Workflow/Apply', $form);
    }

    public function update(DraftApplicationRequest $request): RedirectResponse
    {
        $this->updateDraft->execute(AccessContext::of($request), $request->caseId(), $request->revision(), $request->draftInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => '一時保存しました']);

        return redirect()->route('workflow.drafts.edit', ['case' => $request->caseId()]);
    }

    public function apply(DraftApplicationRequest $request): RedirectResponse
    {
        $result = $this->applyCase->executeFromDraft(AccessContext::of($request), $request->caseId(), $request->revision(), $request->applicationInput(), $request->baseDate());
        Inertia::flash('toast', ['type' => 'success', 'message' => '申請しました']);

        return redirect()->route('workflow.cases.show', ['case' => $result->caseId]);
    }

    public function destroy(DraftDeleteRequest $request): RedirectResponse
    {
        $this->deleteDraft->execute(AccessContext::of($request), $request->caseId(), $request->revision());
        Inertia::flash('toast', ['type' => 'success', 'message' => '一時保存を削除しました']);

        return redirect()->route('workflow.cases.index', ['tab' => 'draft']);
    }
}

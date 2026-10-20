<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\WithdrawCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\CaseActionRequest;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * 案件の処理 6 つ(docs の 設計/ワークフロー/画面.md 1.1・6 節): 承認・承認終了・否認・差戻し・引戻し・取止め。成功は案件の詳細へのリダイレクト(POST は 302)と
 * 成功のトースト(処理した人は処理済の述語でいつも見られる = 認可と一覧.md 2 節)。失敗(改訂番号・表・0 人 = case、コメント、差戻し先)は元の画面へ戻りキーにエラーが付く。
 */
class CaseActionController extends Controller
{
    public function __construct(
        private ApproveCase $approveCase,
        private ApproveCaseEarly $approveCaseEarly,
        private RejectCase $rejectCase,
        private SendBackCase $sendBackCase,
        private PullBackCase $pullBackCase,
        private WithdrawCase $withdrawCase,
    ) {}

    public function approve(CaseActionRequest $request): RedirectResponse
    {
        $this->approveCase->execute(AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(), $request->comment(), $request->assignments());

        return $this->redirectToCase($request, '承認しました');
    }

    public function approveEarly(CaseActionRequest $request): RedirectResponse
    {
        $this->approveCaseEarly->execute(AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(), $request->comment());

        return $this->redirectToCase($request, '承認終了しました');
    }

    public function reject(CaseActionRequest $request): RedirectResponse
    {
        $this->rejectCase->execute(AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(), $request->comment());

        return $this->redirectToCase($request, '否認しました');
    }

    public function sendBack(CaseActionRequest $request): RedirectResponse
    {
        $this->sendBackCase->execute(AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(), $request->sendBackNodeId(), $request->comment());

        return $this->redirectToCase($request, '差戻しました');
    }

    public function pullBack(CaseActionRequest $request): RedirectResponse
    {
        $this->pullBackCase->execute(AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(), $request->comment());

        return $this->redirectToCase($request, '引戻しました');
    }

    public function withdraw(CaseActionRequest $request): RedirectResponse
    {
        $this->withdrawCase->execute(AccessContext::of($request), $request->caseId(), $request->revision(), $request->comment());

        return $this->redirectToCase($request, '取止めました');
    }

    private function redirectToCase(CaseActionRequest $request, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('workflow.cases.show', ['case' => $request->caseId()]);
    }
}

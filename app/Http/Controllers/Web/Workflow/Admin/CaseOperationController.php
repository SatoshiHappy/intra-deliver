<?php

namespace App\Http\Controllers\Web\Workflow\Admin;

use App\Actions\Workflow\DeleteCase;
use App\Actions\Workflow\MoveCaseNode;
use App\Actions\Workflow\ReassignCaseNode;
use App\Actions\Workflow\ReexpandCaseNode;
use App\Actions\Workflow\RetargetCaseNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\CaseOperationRequest;
use App\Queries\Workflow\CaseOperationList;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 申請操作(管理のメニューの一覧と案件操作の 5 ルート。docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、
 * 案件の運用/案件の操作/案件操作.md の 15 節)。システム管理者だけが通るのはルートの認可(ポリシーを置かない = 案件の運用/認可と一覧.md の 4 節)。
 * どの操作も案件操作の判定の入口を通る業務処理を呼ぶ(入口は 404・403 を先に確かめるだけ)。成功は詳細(削除は申請操作の一覧)へのリダイレクト
 * (POST は 302、DELETE は 303)とトースト。業務処理の 422 は元の画面へ戻りキーにエラーが付く。
 */
class CaseOperationController extends Controller
{
    public function __construct(
        private CaseOperationList $list,
        private RetargetCaseNode $retargetCaseNode,
        private ReexpandCaseNode $reexpandCaseNode,
        private MoveCaseNode $moveCaseNode,
        private ReassignCaseNode $reassignCaseNode,
        private DeleteCase $deleteCase,
    ) {}

    public function index(Request $request): Response
    {
        $props = $this->list->list(
            AccessContext::of($request),
            $request->query('flow'),
            $request->query('scope'),
            $request->query('q'),
            $request->query('overdue'),
            $request->query('page'),
        );

        return Inertia::render('Workflow/Admin/CaseOperations', $props);
    }

    public function retarget(CaseOperationRequest $request): RedirectResponse
    {
        $this->retargetCaseNode->execute(
            AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(),
            $request->userIds(), $request->comment(),
        );

        return $this->redirectToCase($request, '処理担当者を変更しました');
    }

    public function reexpand(CaseOperationRequest $request): RedirectResponse
    {
        $this->reexpandCaseNode->execute(
            AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(),
            $request->comment(),
        );

        return $this->redirectToCase($request, '処理担当者を再展開しました');
    }

    public function move(CaseOperationRequest $request): RedirectResponse
    {
        $this->moveCaseNode->execute(
            AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(),
            $request->direction(), $request->sendBackNodeId(), $request->comment(),
        );

        return $this->redirectToCase($request, 'ステップを移動しました');
    }

    public function assign(CaseOperationRequest $request): RedirectResponse
    {
        $this->reassignCaseNode->execute(
            AccessContext::of($request), $request->caseId(), $request->revision(), $request->nodeId(),
            $request->userIds(), $request->comment(),
        );

        return $this->redirectToCase($request, '指定を変更しました');
    }

    public function destroy(CaseOperationRequest $request): RedirectResponse
    {
        $this->deleteCase->execute(AccessContext::of($request), $request->caseId(), $request->revision());
        Inertia::flash('toast', ['type' => 'success', 'message' => '申請を削除しました']);

        return redirect()->route('workflow.admin.cases.index', [], 303);
    }

    private function redirectToCase(CaseOperationRequest $request, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return redirect()->route('workflow.cases.show', ['case' => $request->caseId()]);
    }
}

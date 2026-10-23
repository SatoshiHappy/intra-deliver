<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\BulkApproveCases;
use App\Actions\Workflow\BulkConfirmCases;
use App\Actions\Workflow\BulkOperationResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\BulkApproveRequest;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 一括承認・一括確認(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の操作/操作.md の 10.7 節の「応答」)。申請一覧へ戻る
 * (一括承認は未処理、一括確認は未確認のタブ)。全部が成功ならトースト、ほかは結果の値 bulkResult をフラッシュで渡す。入力の誤りは業務処理の
 * 422 で元の画面へ戻りキーにエラーが付く(何も処理しない)。
 */
class BulkActionController extends Controller
{
    public function __construct(
        private BulkApproveCases $approveCases,
        private BulkConfirmCases $confirmCases,
    ) {}

    public function approve(BulkApproveRequest $request): RedirectResponse
    {
        $result = $this->approveCases->execute(AccessContext::of($request), $request->cases(), $request->comment());

        return $this->redirectToList($result, 'pending', '承認しました');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $result = $this->confirmCases->execute(AccessContext::of($request), $request->input('case_ids'));

        return $this->redirectToList($result, 'unconfirmed', '確認しました');
    }

    private function redirectToList(BulkOperationResult $result, string $tab, string $done): RedirectResponse
    {
        if ($result->succeeded() === $result->total()) {
            Inertia::flash('toast', ['type' => 'success', 'message' => "{$result->total()} 件を{$done}"]);
        } else {
            Inertia::flash('bulkResult', $result->toScreen());
        }

        return redirect()->route('workflow.cases.index', ['tab' => $tab]);
    }
}

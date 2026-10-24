<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\ConfirmCase;
use App\Http\Controllers\Controller;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * 確認(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/操作.md の 10.4 節)。入力は無く、
 * 閲覧できない案件の 404 と確認できる確認が無い 422 case は業務処理が投げる。成功は詳細へ戻りトースト「確認しました」。
 */
class CaseConfirmationController extends Controller
{
    public function __construct(private ConfirmCase $confirmCase) {}

    public function store(Request $request): RedirectResponse
    {
        // 引数の形 [0-9]{1,18} はルートが限る
        $caseId = (int) $request->route('case');
        $this->confirmCase->execute(AccessContext::of($request), $caseId);
        Inertia::flash('toast', ['type' => 'success', 'message' => '確認しました']);

        return redirect()->route('workflow.cases.show', ['case' => $caseId]);
    }
}

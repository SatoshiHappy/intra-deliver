<?php

namespace App\Http\Controllers\Web\Workflow\Admin;

use App\Actions\Workflow\SaveFlowReferrers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowReferrersRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * 参照者の保存(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、設計/ワークフロー/ルートの深さ/定義の管理.md 5.2 節)。成功は詳細のタブ「設定」(面「参照者」がある)へ移りトースト。
 * 業務処理の 422 は元の画面へ戻りキーにエラーが付く。
 */
class ReferrerController extends Controller
{
    public function __construct(private SaveFlowReferrers $saveFlowReferrers) {}

    public function update(FlowReferrersRequest $request): RedirectResponse
    {
        $this->saveFlowReferrers->execute($request->flowCode(), $request->referrers());
        Inertia::flash('toast', ['type' => 'success', 'message' => '参照者を保存しました']);

        $query = ['flow' => $request->flowCode(), 'tab' => 'settings'];

        return redirect()->route('workflow.admin.flows.show', $query, 303);
    }
}

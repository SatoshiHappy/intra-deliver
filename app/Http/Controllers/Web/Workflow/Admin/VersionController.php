<?php

namespace App\Http\Controllers\Web\Workflow\Admin;

use App\Actions\Workflow\CopyFlowVersion;
use App\Actions\Workflow\SetFlowVersionDeleted;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowDefinitionRequest;
use App\Http\Requests\Workflow\FlowVersionRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * 版の追加・無効・有効(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、定義の管理.md 1 節・4 節)。成功は詳細の版のタブ
 * (追加は足した版、無効・有効はその版を選んだ状態)へ移りトースト。業務処理の 422 は元の画面へ戻りキーにエラーが付く。
 */
class VersionController extends Controller
{
    public function __construct(
        private CopyFlowVersion $copyFlowVersion,
        private SetFlowVersionDeleted $setFlowVersionDeleted,
    ) {}

    public function store(FlowVersionRequest $request): RedirectResponse
    {
        $number = $this->copyFlowVersion->execute($request->flowCode(), $request->startDate());

        return $this->redirectToVersions($request->flowCode(), $number, '版を追加しました');
    }

    public function disable(FlowDefinitionRequest $request): RedirectResponse
    {
        $number = (int) $request->versionNumber();
        $this->setFlowVersionDeleted->execute($request->flowCode(), $number, true);

        return $this->redirectToVersions($request->flowCode(), $number, '版を無効にしました');
    }

    public function enable(FlowDefinitionRequest $request): RedirectResponse
    {
        $number = (int) $request->versionNumber();
        $this->setFlowVersionDeleted->execute($request->flowCode(), $number, false);

        return $this->redirectToVersions($request->flowCode(), $number, '版を有効にしました');
    }

    private function redirectToVersions(string $flowCode, int $number, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        $query = ['flow' => $flowCode, 'tab' => 'versions', 'version' => $number];

        return redirect()->route('workflow.admin.flows.show', $query);
    }
}

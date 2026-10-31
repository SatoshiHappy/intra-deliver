<?php

namespace App\Http\Controllers\Web\Workflow\Admin;

use App\Actions\Workflow\SaveNodeSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowNodeSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * ステップの設定の保存(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、定義の管理.md 5.1 節)。成功は詳細のフロー図のタブ
 * (その版のそのノードを選んだ状態)へ移りトースト。業務処理の 422 は元の画面へ戻りキーにエラーが付く。
 */
class NodeController extends Controller
{
    public function __construct(private SaveNodeSettings $saveNodeSettings) {}

    public function update(FlowNodeSettingsRequest $request): RedirectResponse
    {
        $flowCode = $request->flowCode();
        $number = (int) $request->versionNumber();
        $nodeCode = (string) $request->nodeCode();
        $this->saveNodeSettings->execute($flowCode, $number, $nodeCode, $request->settings());
        Inertia::flash('toast', ['type' => 'success', 'message' => 'ステップの設定を保存しました']);

        return redirect()->route('workflow.admin.flows.show', [
            'flow' => $flowCode, 'tab' => 'diagram', 'version' => $number, 'node' => $nodeCode,
        ], 303);
    }
}

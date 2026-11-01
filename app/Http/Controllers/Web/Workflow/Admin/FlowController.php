<?php

namespace App\Http\Controllers\Web\Workflow\Admin;

use App\Actions\Workflow\UpdateFlowSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\FlowSettingsRequest;
use App\Queries\Workflow\FlowDefinitionDetail;
use App\Queries\Workflow\FlowDefinitionList;
use App\Queries\Workflow\PersonLinks;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * フロー定義の一覧と詳細と機能設定の保存(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、定義の管理の画面.md 2 節・3 節、
 * 設計/ワークフロー/申請の周辺/画面.md の 4 節。保存の成功は詳細のタブ「設定」へ移りトースト)。
 * システム管理者だけが通るのはルートの認可(ポリシーを置かない = 設計/ワークフロー/ルートの深さ/認可と一覧.md 4.2 節)。詳細のクエリの読み方は {@see FlowDefinitionDetail}。
 */
class FlowController extends Controller
{
    public function __construct(
        private FlowDefinitionList $list,
        private FlowDefinitionDetail $detail,
        private UpdateFlowSettings $updateFlowSettings,
        private PersonLinks $links,
    ) {}

    public function index(Request $request): Response
    {
        $context = AccessContext::of($request);

        return Inertia::render('Workflow/Admin/FlowIndex', [
            'flows' => $this->list->flows($context->date, $context->localeCode),
        ]);
    }

    public function show(Request $request, string $flow): Response
    {
        $context = AccessContext::of($request);
        $props = $this->detail->show(
            $flow,
            $request->query('version'),
            $request->query('tab'),
            $request->query('node'),
            $context->date,
            $context->localeCode,
        );

        return Inertia::render('Workflow/Admin/FlowShow', [...$props, 'links' => $this->links->lazy($context)]);
    }

    public function updateSettings(FlowSettingsRequest $request): RedirectResponse
    {
        $this->updateFlowSettings->execute($request->flowCode(), $request->isAttachmentEnabled());
        Inertia::flash('toast', ['type' => 'success', 'message' => '添付ファイルの設定を保存しました']);

        $query = ['flow' => $request->flowCode(), 'tab' => 'settings'];

        return redirect()->route('workflow.admin.flows.show', $query, 303);
    }
}

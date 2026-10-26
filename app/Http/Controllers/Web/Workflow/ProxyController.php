<?php

namespace App\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\DeleteProxy;
use App\Actions\Workflow\RegisterProxy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\ProxyRequest;
use App\Queries\Workflow\ProxyList;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 代理の設定(利用者メニュー。docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/操作.md の 10.9 節)。
 * 代理元は自分。ゲストは一覧が空で、追加と削除は 403。代理元が自分でない行・無い行の削除は 404(業務処理が投げる)。
 * 成功も検証の失敗も元の画面へ戻る(Referer も直前の URL も無ければ代理の設定)。成功はトースト。
 */
class ProxyController extends Controller
{
    public function __construct(
        private ProxyList $proxies,
        private RegisterProxy $registerProxy,
        private DeleteProxy $deleteProxy,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Workflow/Proxies', $this->proxies->own(AccessContext::of($request)));
    }

    public function store(ProxyRequest $request): RedirectResponse
    {
        $context = AccessContext::of($request);
        $this->registerProxy->execute($context, $context->userId, $request->proxyInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => '代理を追加しました']);

        return redirect()->back(fallback: route('workflow.proxies.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        // 引数の形 [0-9]{1,18} はルートが限る
        $this->deleteProxy->executeOwn(AccessContext::of($request), (int) $request->route('proxy'));
        Inertia::flash('toast', ['type' => 'success', 'message' => '代理を削除しました']);

        return redirect()->back(303, fallback: route('workflow.proxies.index'));
    }
}

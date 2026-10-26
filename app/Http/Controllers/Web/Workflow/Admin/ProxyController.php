<?php

namespace App\Http\Controllers\Web\Workflow\Admin;

use App\Actions\Workflow\DeleteProxy;
use App\Actions\Workflow\RegisterProxy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\AdminProxyRequest;
use App\Queries\Workflow\ProxyList;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 代理の管理(管理のメニュー。docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の運用/案件の操作/操作.md の 10.9 節)。
 * システム管理者だけが通るのはルートの認可(ポリシーを置かない = 案件の運用/認可と一覧.md の 4 節)。代理元は入力で選び、どの行も消せる
 * (無い行は 404)。成功も検証の失敗も元の画面へ戻る(絞り込みとページを残す。Referer も直前の URL も無ければ一覧)。
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
        $props = $this->proxies->admin(
            AccessContext::of($request),
            $request->query('principal'),
            $request->query('proxy'),
            $request->query('kind'),
            $request->query('active'),
            $request->query('page'),
        );

        return Inertia::render('Workflow/Admin/Proxies', $props);
    }

    public function store(AdminProxyRequest $request): RedirectResponse
    {
        $this->registerProxy->execute(AccessContext::of($request), $request->principalUserId(), $request->proxyInput());
        Inertia::flash('toast', ['type' => 'success', 'message' => '代理を追加しました']);

        return redirect()->back(fallback: route('workflow.admin.proxies.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        // 引数の形 [0-9]{1,18} はルートが限る
        $this->deleteProxy->execute((int) $request->route('proxy'));
        Inertia::flash('toast', ['type' => 'success', 'message' => '代理を削除しました']);

        return redirect()->back(303, fallback: route('workflow.admin.proxies.index'));
    }
}

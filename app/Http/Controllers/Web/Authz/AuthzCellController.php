<?php

namespace App\Http\Controllers\Web\Authz;

use App\Actions\Authz\ChangeAuthzCell;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authz\AuthzCellRequest;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;

/**
 * セルの切替(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.2): 認可設定の画面のセルを押すたびに 1 回保存する。応答は元の画面へ戻す
 * (画面は部分再読み込みで grid・judge・can を取り直す)。検証の失敗は元の画面へ戻り、入力の名前のキーか cell にエラーが付く。
 * 編集できるのはルートの認可が許す人だけ(初期データではシステム管理者)。
 */
class AuthzCellController extends Controller
{
    public function __construct(private ChangeAuthzCell $changeCell) {}

    public function update(AuthzCellRequest $request): RedirectResponse
    {
        $context = AccessContext::of($request);
        $this->changeCell->execute($context, $request->cell(), $request->effect('from'), $request->effect('to'));

        return back();
    }
}

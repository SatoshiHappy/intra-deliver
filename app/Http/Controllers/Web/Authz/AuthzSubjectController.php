<?php

namespace App\Http\Controllers\Web\Authz;

use App\Actions\Authz\AddAuthzColumn;
use App\Actions\Authz\DeleteAuthzColumn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Authz\AuthzSubjectDestroyRequest;
use App\Http\Requests\Authz\AuthzSubjectStoreRequest;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;

/**
 * 認可設定の列(対象者)の追加と削除(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3 節)。応答は元の画面へ戻す。
 * 検証の失敗は元の画面へ戻り、追加は target_id か kind、削除は subject(か入力の名前のキー)にエラーが付く。{subject} = 対象者の id(数字だけ)。
 */
class AuthzSubjectController extends Controller
{
    public function __construct(
        private AddAuthzColumn $addColumn,
        private DeleteAuthzColumn $deleteColumn,
    ) {}

    public function store(AuthzSubjectStoreRequest $request): RedirectResponse
    {
        $this->addColumn->execute($request->kind(), $request->targetId(), AccessContext::of($request)->date);

        return back();
    }

    public function destroy(AuthzSubjectDestroyRequest $request, string $subject): RedirectResponse
    {
        $context = AccessContext::of($request);
        $this->deleteColumn->execute($context, (int) $subject, $request->policyCount(), $request->fingerprint());

        return back();
    }
}

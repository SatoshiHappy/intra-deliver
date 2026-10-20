<?php

namespace App\Http\Controllers\Web\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ログアウト(docs の 設計/デモユーザー切替/今の利用者.md 5.6): Laravel の定石どおり Auth::logout()、セッションの無効化
 * (中身を消して ID を作り直す)、CSRF のトークンの作り直しの順に行い、/ へ戻す(README の方針 8)。ゲストが呼んでも同じく / へ戻す。
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}

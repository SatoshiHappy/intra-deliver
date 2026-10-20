<?php

namespace App\Http\Controllers\Web\Demo;

use App\Actions\Account\LogIn;
use App\Http\Controllers\Controller;
use App\Http\Requests\Demo\SwitchUserRequest;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;

/**
 * 利用者の切替(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3): 候補のユーザー(検証は {@see SwitchUserRequest})を、main のログイン
 * {@see LogIn} で要求の d に今の利用者にし、/ へ戻す(docs の 設計/デモユーザー切替/README.md の方針 8)。
 * セッションの中身を消すことと ID の作り直しは LogIn が行う。
 */
class SwitchUserController extends Controller
{
    public function __invoke(SwitchUserRequest $request, LogIn $logIn): RedirectResponse
    {
        $logIn->execute($request->integer('user_id'), AccessContext::of($request)->date);

        return redirect('/');
    }
}

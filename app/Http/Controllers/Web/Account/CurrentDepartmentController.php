<?php

namespace App\Http\Controllers\Web\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateCurrentDepartmentRequest;
use App\Support\AccessContext;
use Illuminate\Http\RedirectResponse;

/**
 * カレント組織の切替(docs の 設計/デモユーザー切替/今の利用者.md 5.5): 候補にある組織(検証は {@see UpdateCurrentDepartmentRequest})を、
 * 利用者が選んだ組織としてセッションに置き、/ へ戻す(README の方針 8)。DB には書かず、セッション ID は作り直さない(利用者が変わらない)。
 */
class CurrentDepartmentController extends Controller
{
    public function __invoke(UpdateCurrentDepartmentRequest $request): RedirectResponse
    {
        $request->session()->put(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY, $request->integer('department_id'));

        return redirect('/');
    }
}

<?php

namespace App\Http\Middleware\Account;

use App\Queries\Account\CurrentAccountQuery;
use App\Support\AccessContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 今の利用者が基準日 d に有効でなければ、その要求の始めにガードから外す(ゲストに戻す。docs の 設計/デモユーザー切替/今の利用者.md 5.4)。
 * 起動の配線が web グループに追加するものの先頭に付ける(セッションを始めた後、ルートの認可と共有より前)。
 *
 * {@see AccessContext::of()} は呼ばない(呼ぶと外す前の利用者が要求に覚えられ、後の判定の入力と共有に出る)ので、
 * 利用者の識別子は {@see AccessContext::userIdOf()}、d は {@see AccessContext::dateOf()} で得る(入口はモデルを持たない)。
 */
class LogOutIneffectiveUser
{
    public function __construct(private CurrentAccountQuery $accounts) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = AccessContext::userIdOf($request);

        if ($userId !== null && ! $this->accounts->isEffectiveUserOn($userId, AccessContext::dateOf($request))) {
            Auth::logout();
        }

        return $next($request);
    }
}

<?php

namespace App\Actions\Account;

use App\Models\Master\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;

/**
 * ログイン = d に有効なユーザーを今の利用者にする(docs の 設計/デモユーザー切替/今の利用者.md 5.6)。パスワードは確かめない。
 *
 * 利用者が変わる操作はセッションの中身を引き継がない: 検証の後に Session::invalidate()(中身を全部消して ID を作り直す)、
 * その後に Auth::login(セッション ID をもう一度作り直す。remember は使わない)。検証に失敗したときは今のセッションを壊さない。
 * DB には書かない(セッションと認証のイベントは副作用に数えない = docs の 設計/コード構成/README.md 4 節)。
 */
class LogIn
{
    /**
     * @throws ValidationException ユーザーが d に有効でない(存在しないを含む)とき。項目名 user_id
     */
    public function execute(int $userId, DateTimeInterface $date): void
    {
        $user = User::query()->whereKey($userId)->effectiveOn($date)->first()
            ?? throw ValidationException::withMessages(['user_id' => ['ユーザーが今日有効ではありません']]);

        Session::invalidate();
        Auth::login($user);
    }
}

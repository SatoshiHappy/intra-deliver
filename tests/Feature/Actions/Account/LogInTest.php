<?php

namespace Tests\Feature\Actions\Account;

use App\Actions\Account\LogIn;
use App\Support\AccessContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ログイン(docs の 設計/デモユーザー切替/今の利用者.md 5.6・5.9): d に有効なユーザーを今の利用者にし、セッションの ID を作り直し、
 * 前の利用者のセッションの値(選んだ組織と任意の値)を引き継がない。d に有効でない・存在しないユーザーは項目名 user_id で拒み、
 * そのときは今の利用者とセッションをそのまま残す。
 */
final class LogInTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use BuildsCommonMaster;

    public function test_logs_in_an_effective_user_with_a_new_session_that_carries_nothing_over(): void
    {
        Auth::login($this->user());
        $this->putValuesOfThePreviousUser();
        $previousSessionId = Session::getId();
        $user = $this->user();

        app(LogIn::class)->execute($user->id, self::day('2026-10-10'));

        $this->assertSame($user->id, Auth::id());
        $this->assertNotSame($previousSessionId, Session::getId());
        $this->assertFalse(Session::has(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY));
        $this->assertFalse(Session::has('any'));
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2?: bool}>|null  $terms  ユーザーの期間(null = 存在しないユーザー)
     */
    #[TestWith([[['2020-01-01', '2026-10-10']]])]
    #[TestWith([[['2026-10-11', null]]])]
    #[TestWith([[['2020-01-01', null, true]]])]
    #[TestWith([null])]
    public function test_rejects_a_user_who_is_not_effective_on_the_date_and_keeps_the_session(?array $terms): void
    {
        $previousUser = $this->user();
        Auth::login($previousUser);
        $this->putValuesOfThePreviousUser();
        $previousSessionId = Session::getId();
        $userId = $terms === null ? $previousUser->id + 1000 : $this->user($terms)->id;

        $this->assertRejected('user_id', fn () => app(LogIn::class)->execute($userId, self::day('2026-10-10')));

        $this->assertSame($previousUser->id, Auth::id());
        $this->assertSame($previousSessionId, Session::getId());
        $this->assertSame(12, Session::get(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY));
        $this->assertSame('value', Session::get('any'));
    }

    private function putValuesOfThePreviousUser(): void
    {
        Session::put(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY, 12);
        Session::put('any', 'value');
    }
}

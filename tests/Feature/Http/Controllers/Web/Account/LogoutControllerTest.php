<?php

namespace Tests\Feature\Http\Controllers\Web\Account;

use App\Models\Master\User;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * ログアウト(docs の 設計/デモユーザー切替/今の利用者.md 5.6・5.8・5.9): POST /account/logout。利用者が消え、セッションの中身が消えて
 * ID が作り直され、/ へ戻り、次の要求でゲストになる。ゲストが呼んでも / へ戻す。POST だけを受ける。
 * 利用者はセッションのガードの鍵で置く(次の要求でガードがセッションから引き直すことを確かめるため)。
 * 初期データを入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class LogoutControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_logs_out_invalidates_the_session_and_the_next_request_is_the_guest(): void
    {
        $sessionId = Str::random(40);
        $user = User::query()->where('code', 'u0009')->sole();

        $this->withCookie((string) config('session.cookie'), $sessionId)
            ->withSession([$this->guardSessionKey() => $user->id, AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => 1, 'any' => 'value'])
            ->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('account.user.code', 'u0009'));

        $response = $this->post('/account/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
        $this->assertNotSame($sessionId, session()->getId());
        $response->assertSessionMissing($this->guardSessionKey());
        $response->assertSessionMissing(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY);
        $response->assertSessionMissing('any');

        Auth::forgetGuards();
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('account.user', null));
    }

    public function test_returns_the_guest_home_too(): void
    {
        $this->post('/account/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_accepts_only_post(): void
    {
        $this->get('/account/logout')->assertMethodNotAllowed();
    }

    /**
     * 既定のガード(セッションガード)が利用者の ID を置くセッションの鍵。
     */
    private function guardSessionKey(): string
    {
        $guard = Auth::guard();
        $this->assertInstanceOf(SessionGuard::class, $guard);

        return $guard->getName();
    }
}

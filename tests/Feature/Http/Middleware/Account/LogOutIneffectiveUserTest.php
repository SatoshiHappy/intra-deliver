<?php

namespace Tests\Feature\Http\Middleware\Account;

use App\Models\Master\Department;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * 今日有効でない今の利用者を、その要求でガードから外す(docs の 設計/デモユーザー切替/今の利用者.md 5.4・5.9)。外した後の判定はゲスト
 * (ゲストに許したルートだけ通る)で、共有の account.user は null。判定の入力のユーザーが null になることは
 * tests/Feature/Support/AccessContextRequestTest.php が確かめる。
 * 初期データ(退職者 u0014 = 2025-10-01 で終わる、兼務の u0009)を入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class LogOutIneffectiveUserTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_logs_out_a_user_who_is_not_effective_today_in_that_request_and_shares_the_guest(): void
    {
        $response = $this->actingAs($this->userOf('u0014'))->get('/');

        $response->assertInertia(fn (Assert $page) => $page->component('Menu/Home')->where('account.user', null));
        $this->assertGuest();
    }

    public function test_the_user_who_is_not_effective_today_is_judged_as_the_guest(): void
    {
        $response = $this->actingAs($this->userOf('u0014'))->post('/account/current-department', [
            'department_id' => Department::query()->where('code', 'accounting')->sole()->id,
        ]);

        $response->assertForbidden();
        $this->assertGuest();
    }

    public function test_keeps_a_user_who_is_effective_today(): void
    {
        $user = $this->userOf('u0009');

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (Assert $page) => $page->where('account.user.code', 'u0009'));
        $this->assertAuthenticatedAs($user);
    }

    private function userOf(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}

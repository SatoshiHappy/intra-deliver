<?php

namespace Tests\Feature\Http\Controllers\Web\Account;

use App\Models\Master\Department;
use App\Models\Master\User;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\TransactionalTestCase;

/**
 * カレント組織の切替(docs の 設計/デモユーザー切替/今の利用者.md 5.5・5.8・5.9): POST /account/current-department。候補(今日成り立つ、
 * 既定の組織セットの所属の組織)なら選んだ組織としてセッションに置いて / へ戻し、セッション ID は作り直さない。候補でなければ項目名
 * department_id の検証のエラー。ゲストは 403(ルートの認可が認証済みユーザにだけ許す)。POST だけを受ける。
 * 初期データ(兼務の u0009 = 総務部が主所属、人事部を兼務)を入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class CurrentDepartmentControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
    }

    public function test_puts_a_candidate_in_the_session_and_returns_home_without_a_new_session_id(): void
    {
        $sessionId = Str::random(40);
        $humanResources = $this->departmentId('human-resources');

        $response = $this->actingAs($this->userOf('u0009'))
            ->withCookie((string) config('session.cookie'), $sessionId)
            ->post('/account/current-department', ['department_id' => $humanResources]);

        $response->assertRedirect('/');
        $response->assertSessionHas(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY, $humanResources);
        $this->assertSame($sessionId, session()->getId());
    }

    public function test_rejects_a_department_that_is_not_a_candidate(): void
    {
        $response = $this->actingAs($this->userOf('u0009'))
            ->post('/account/current-department', ['department_id' => $this->departmentId('accounting')]);

        $response->assertSessionHasErrors('department_id');
        $response->assertSessionMissing(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY);
    }

    public function test_rejects_a_missing_or_non_integer_department(): void
    {
        $user = $this->userOf('u0009');

        $this->actingAs($user)->post('/account/current-department', [])->assertSessionHasErrors('department_id');
        $this->actingAs($user)->post('/account/current-department', ['department_id' => 'general-affairs'])->assertSessionHasErrors('department_id');
    }

    public function test_forbids_the_guest(): void
    {
        $this->post('/account/current-department', ['department_id' => $this->departmentId('general-affairs')])->assertForbidden();
    }

    public function test_accepts_only_post(): void
    {
        $this->actingAs($this->userOf('u0009'))->get('/account/current-department')->assertMethodNotAllowed();
    }

    private function userOf(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }
}

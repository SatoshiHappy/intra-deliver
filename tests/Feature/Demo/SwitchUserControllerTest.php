<?php

namespace Tests\Feature\Demo;

use App\Models\Master\User;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 利用者の切替(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3・6.5): POST /demo/switch-user。候補なら今の利用者が変わり、セッションの
 * 中身が消えて ID が作り直され、/ へ戻る(ゲストからも、ほかの利用者からも)。候補でない・今日有効でない・無い・整数でない user_id は
 * 項目名 user_id の検証のエラーで、今の利用者は変わらない(docs の 設計/デモユーザー切替/README.md の方針 7)。POST だけを受ける。
 * オンで起動し({@see EnablesDemoBeforeBoot})、初期データと DemoSeeder を入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 * セッション ID は cookie で決めて送り、要求の後の ID と比べる。
 */
final class SwitchUserControllerTest extends TransactionalTestCase
{
    use EnablesDemoBeforeBoot;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->seed(DemoSeeder::class);
    }

    public function test_a_guest_switches_to_a_candidate_with_a_new_session(): void
    {
        $sessionId = Str::random(40);

        $response = $this->withCookie((string) config('session.cookie'), $sessionId)
            ->post('/demo/switch-user', ['user_id' => $this->userId('u0001')]);

        $response->assertRedirect('/');
        $this->assertSame($this->userId('u0001'), Auth::id());
        $this->assertNotSame($sessionId, session()->getId());
    }

    public function test_a_user_switches_to_another_candidate_and_carries_nothing_over(): void
    {
        $sessionId = Str::random(40);
        $previous = User::query()->where('code', 'u0009')->sole();

        $response = $this->actingAs($previous)
            ->withCookie((string) config('session.cookie'), $sessionId)
            ->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => 1, 'any' => 'value'])
            ->post('/demo/switch-user', ['user_id' => $this->userId('u0010')]);

        $response->assertRedirect('/');
        $this->assertSame($this->userId('u0010'), Auth::id());
        $this->assertNotSame($sessionId, session()->getId());
        $response->assertSessionMissing(AccessContext::CURRENT_DEPARTMENT_SESSION_KEY);
        $response->assertSessionMissing('any');

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('account.user.code', 'u0010'));
    }

    public function test_rejects_a_user_who_is_not_listed(): void
    {
        $this->post('/demo/switch-user', ['user_id' => $this->userId('u0005')])->assertSessionHasErrors('user_id');
        $this->assertGuest();
    }

    public function test_rejects_a_listed_user_who_is_not_effective_today(): void
    {
        config(['demo.users' => ['u0014' => ['role' => '退職者', 'can' => '退職者の文'], 'u0001' => ['role' => '申請者', 'can' => '申請者の文']]]);

        $this->post('/demo/switch-user', ['user_id' => $this->userId('u0014')])->assertSessionHasErrors('user_id');
        $this->assertGuest();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[TestWith([[]])]
    #[TestWith([['user_id' => 'u0001']])]
    #[TestWith([['user_id' => 999999]])]
    public function test_rejects_a_missing_malformed_or_unknown_user_id(array $input): void
    {
        $this->post('/demo/switch-user', $input)->assertSessionHasErrors('user_id');
        $this->assertGuest();
    }

    public function test_keeps_the_current_user_when_rejected(): void
    {
        $user = User::query()->where('code', 'u0001')->sole();

        $this->actingAs($user)->post('/demo/switch-user', ['user_id' => $this->userId('u0005')])->assertSessionHasErrors('user_id');
        $this->assertSame($user->id, Auth::id());
    }

    public function test_accepts_only_post(): void
    {
        $this->get('/demo/switch-user')->assertMethodNotAllowed();
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}

<?php

namespace Tests\Feature\Http\Middleware\Notice;

use App\Models\Master\User;
use App\Models\Notice\Notice;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の共有(docs の 設計/ワークフロー/案件の運用/通知.md の 7 節): 未読の数 noticeSummary はゲストに null、利用者には全ページ(通知の一覧のページを含む)に
 * 渡し、部分の再読み込みでも新しくする。鈴の最新 10 件は共有しない(notice.latest の JSON で引く = NoticeControllerTest)。
 * ルートの認可を外さずに開く。
 */
final class ShareNoticesTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith(['/', 'Menu/Home'])]
    #[TestWith(['/notices', 'Notice/Index'])]
    public function test_shares_no_summary_with_a_guest(string $path, string $component): void
    {
        $this->get($path)->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('noticeSummary', null)
        );
    }

    #[TestWith(['/', 'Menu/Home'])]
    #[TestWith(['/menu/sitemap', 'Menu/Sitemap'])]
    #[TestWith(['/notices', 'Notice/Index'])]
    public function test_shares_the_unread_count(string $path, string $component): void
    {
        $user = $this->user();
        Notice::factory()->count(2)->create(['user_id' => $user->id]);

        $this->actingAs($user)->get($path)->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('noticeSummary', ['unreadCount' => 2])
        );
    }

    public function test_renews_the_unread_count_on_a_partial_reload_of_other_props(): void
    {
        $user = $this->user();
        Notice::factory()->createOne(['user_id' => $user->id]);

        $this->actingAs($user)->get('/')->assertInertia(function (Assert $page) use ($user): void {
            $page->where('noticeSummary', ['unreadCount' => 1]);
            Notice::factory()->createOne(['user_id' => $user->id]);
            $page->reloadOnly('globalNavigation', fn (Assert $reload) => $reload
                ->where('noticeSummary', ['unreadCount' => 2])
            );
        });
    }

    public function test_the_notice_page_shares_the_summary_apart_from_its_own_list(): void
    {
        $user = $this->user();
        Notice::factory()->count(3)->create(['user_id' => $user->id]);
        Notice::factory()->createOne(['user_id' => $user->id, 'read_at' => now()]);

        $this->actingAs($user)->get('/notices?filter=unread')->assertInertia(fn (Assert $page) => $page
            ->where('noticeSummary', ['unreadCount' => 3])
            ->where('notices.total', 3)
        );
    }

    private function user(): User
    {
        return User::query()->where('code', 'u0001')->sole();
    }
}

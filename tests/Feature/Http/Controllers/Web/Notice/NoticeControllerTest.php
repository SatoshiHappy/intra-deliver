<?php

namespace Tests\Feature\Http\Controllers\Web\Notice;

use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Queries\Notice\Api\NoticeTargetLink;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RegistersNoticeTargets;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の入口(docs の 設計/ワークフロー/案件の運用/通知.md の 5〜7 節): 通知の一覧のページ、鈴の中の最新 10 件の JSON、行を押した時(既読にして移り先へ・見えなければ元の画面へ戻って
 * トースト・対象が無ければ元の画面へ戻る)、すべて既読にする。ゲストは一覧が空で既読は 403。ルートの認可を外さずに開く。
 */
final class NoticeControllerTest extends TransactionalTestCase
{
    use RegistersNoticeTargets;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->user = User::query()->where('code', 'u0001')->sole();
    }

    public function test_the_index_page_lists_own_notices_with_the_filter_and_the_unread_count(): void
    {
        $unread = Notice::factory()->createOne(['user_id' => $this->user->id]);
        Notice::factory()->createOne(['user_id' => $this->user->id, 'read_at' => now()]);

        $response = $this->actingAs($this->user)->get('/notices?filter=unread');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notice/Index')
            ->where('query', ['filter' => 'unread'])
            ->where('counts', ['unread' => 1])
            ->where('notices.data.0.id', $unread->id)
            ->where('notices.total', 1)
        );
    }

    public function test_a_guest_sees_an_empty_list_and_is_forbidden_to_mark_notices_read(): void
    {
        $notice = Notice::factory()->createOne(['user_id' => $this->user->id]);

        $this->get('/notices')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notice/Index')
            ->where('notices', ['data' => [], 'currentPage' => 1, 'lastPage' => 1, 'total' => 0])
            ->where('counts', ['unread' => 0])
        );
        $this->post("/notices/{$notice->id}/read")->assertForbidden();
        $this->post('/notices/read-all')->assertForbidden();
        $this->assertNull($notice->refresh()->read_at);
    }

    public function test_the_latest_gives_ten_rows_newest_first_with_empty_params_as_an_object_and_is_not_cached(): void
    {
        $notices = Notice::factory()->count(11)->create(['user_id' => $this->user->id]);
        Notice::factory()->createOne(['user_id' => User::query()->where('code', 'u0002')->sole()->id]);

        $response = $this->actingAs($this->user)->getJson('/notices/latest');

        $response->assertOk()->assertJsonCount(10, 'rows');
        $this->assertSame(
            $notices->pluck('id')->reverse()->take(10)->values()->all(),
            array_column($response->json('rows'), 'id'),
        );
        $this->assertStringContainsString('"params":{}', (string) $response->getContent());
        $this->assertTrue($response->headers->getCacheControlDirective('private'));
        $this->assertTrue($response->headers->getCacheControlDirective('no-store'));
    }

    public function test_the_latest_is_empty_for_a_guest(): void
    {
        Notice::factory()->createOne(['user_id' => $this->user->id]);

        $response = $this->getJson('/notices/latest');

        $response->assertOk();
        $this->assertSame('{"rows":[]}', $response->getContent());
    }

    public function test_opening_a_row_with_a_destination_marks_it_read_and_moves_there(): void
    {
        $this->registerNoticeTargets([1 => new NoticeTargetLink('/workflow/cases', null)]);
        $notice = $this->targetNotice(1);

        $this->actingAs($this->user)->from('/notices')->post("/notices/{$notice->id}/read")
            ->assertRedirect('/workflow/cases')
            ->assertInertiaFlashMissing('toast');

        $this->assertNotNull($notice->refresh()->read_at);
    }

    public function test_opening_a_row_whose_target_became_invisible_returns_with_the_line_as_a_toast(): void
    {
        $this->registerNoticeTargets([]);
        $notice = $this->targetNotice(2);

        $this->actingAs($this->user)->from('/notices')->post("/notices/{$notice->id}/read")
            ->assertRedirect('/notices')
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'このテストの対象は閲覧できません。']);

        $this->assertNotNull($notice->refresh()->read_at);
    }

    public function test_opening_a_row_without_a_target_returns_without_a_toast_and_lowers_the_unread_count(): void
    {
        $notice = Notice::factory()->createOne(['user_id' => $this->user->id]);
        Notice::factory()->createOne(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->from('/menu/sitemap')->post("/notices/{$notice->id}/read")
            ->assertRedirect('/menu/sitemap')
            ->assertInertiaFlashMissing('toast');

        $this->get('/menu/sitemap')->assertInertia(fn (Assert $page) => $page
            ->where('noticeSummary', ['unreadCount' => 1])
        );
    }

    public function test_opening_a_row_of_another_user_or_a_missing_row_is_not_found(): void
    {
        $other = Notice::factory()->createOne(['user_id' => User::query()->where('code', 'u0002')->sole()->id]);

        $this->actingAs($this->user)->post("/notices/{$other->id}/read")->assertNotFound();
        $this->actingAs($this->user)->post('/notices/'.($other->id + 1).'/read')->assertNotFound();
        $this->assertNull($other->refresh()->read_at);
    }

    public function test_marking_all_read_returns_with_a_toast_and_leaves_no_own_unread_notice(): void
    {
        Notice::factory()->count(3)->create(['user_id' => $this->user->id]);
        $other = Notice::factory()->createOne(['user_id' => User::query()->where('code', 'u0002')->sole()->id]);

        $this->actingAs($this->user)->from('/notices')->post('/notices/read-all')
            ->assertRedirect('/notices')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'すべて既読にしました']);

        $this->get('/notices')->assertInertia(fn (Assert $page) => $page
            ->where('counts', ['unread' => 0])
            ->where('noticeSummary', ['unreadCount' => 0])
        );
        $this->assertNull($other->refresh()->read_at);
    }

    private function targetNotice(int $targetId): Notice
    {
        return Notice::factory()->withTarget(self::TEST_TARGET_TYPE, $targetId)
            ->createOne(['user_id' => $this->user->id]);
    }
}

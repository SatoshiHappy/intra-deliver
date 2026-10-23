<?php

namespace Tests\Feature\Queries\Notice;

use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Queries\Notice\NoticeList;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\StartsWithoutSeededNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の一覧のページ番号(一覧の 3 つが共有する 1 か所 = App\Support\PageNumbers): 最後のページより大きいページは最後のページを返し、
 * 件数 0 なら 1 ページ目(絞り込みの後の件数で詰める)。読めないページは 1。
 */
final class NoticeListPageTest extends TransactionalTestCase
{
    use StartsWithoutSeededNotices;

    public function test_a_page_past_the_last_page_returns_the_last_page(): void
    {
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $userId = User::query()->where('code', 'u0001')->sole()->id;
        $notices = Notice::factory()->count(21)->create(['user_id' => $userId]);
        $last = $this->page($userId, 'all', '9');

        $this->assertSame([2, 2, 21], [$last['currentPage'], $last['lastPage'], $last['total']]);
        $this->assertSame([$notices->modelKeys()[0]], array_column($last['data'], 'id'));

        $unread = $this->page($userId, 'unread', '2');
        $this->assertSame([2, 21], [$unread['currentPage'], $unread['total']]);

        Notice::query()->update(['read_at' => now()]);
        $empty = $this->page($userId, 'unread', '2');
        $this->assertSame([1, 1, 0, []], [$empty['currentPage'], $empty['lastPage'], $empty['total'], $empty['data']]);

        foreach (['0', '-1', '1.5', 'abc', '1000000000'] as $page) {
            $this->assertSame(1, $this->page($userId, 'all', $page)['currentPage'], $page);
        }
    }

    /**
     * @return array{data: list<array{id: int}>, currentPage: int, lastPage: int, total: int}
     */
    private function page(int $userId, string $filter, string $page): array
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $context = new AccessContext($userId, null, null, $today, AccessContext::LOCALE_CODE);

        return app(NoticeList::class)->page($context, $filter, $page)['notices'];
    }
}

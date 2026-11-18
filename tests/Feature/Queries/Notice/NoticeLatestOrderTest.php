<?php

namespace Tests\Feature\Queries\Notice;

use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Queries\Notice\NoticeList;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\StartsWithoutSeededNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 鈴の 10 件の並び(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.5。ミュートで既読で作った行が未読の行を
 * 鈴から押し出さないように、未読を先に並べる)。一覧の並びは {@see NoticeListTest}。今日を 2026-10-10 に固定する。
 */
final class NoticeLatestOrderTest extends TransactionalTestCase
{
    use StartsWithoutSeededNotices;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->userId = User::query()->where('code', 'u0001')->sole()->id;
    }

    /**
     * 鈴の 10 件は未読を先に、続けて既読(未読どうし・既読どうしは作った日時の新しい順。既読にした時刻によらない)。
     * 一覧は新しい順のまま(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.5)。
     */
    public function test_the_bell_puts_the_unread_first_and_the_page_stays_newest_first(): void
    {
        $oldUnread = $this->ownNotice(['created_at' => '2026-10-05 10:00:00+00']);
        $oldReadLater = $this->ownNotice([
            'created_at' => '2026-10-06 10:00:00+00',
            'read_at' => '2026-10-09 23:00:00+00',
        ]);
        $newRead = $this->ownNotice(['created_at' => '2026-10-08 10:00:00+00', 'read_at' => '2026-10-08 10:00:00+00']);
        $newUnread = $this->ownNotice(['created_at' => '2026-10-07 10:00:00+00']);
        $context = $this->contextOf($this->userId);

        $this->assertSame(
            [$newUnread->id, $oldUnread->id, $newRead->id, $oldReadLater->id],
            array_column($this->list()->latest($context), 'id'),
        );
        $this->assertSame(
            [$newRead->id, $newUnread->id, $oldReadLater->id, $oldUnread->id],
            array_column($this->list()->page($context, null, null)['notices']['data'], 'id'),
        );
    }

    /**
     * 未読の件数の境界: 9 件なら未読 9 行と既読 1 行、10 件・11 件なら未読の新しい 10 行だけ。
     */
    #[TestWith([9, 9])]
    #[TestWith([10, 10])]
    #[TestWith([11, 10])]
    public function test_the_bell_fills_the_rest_with_read_rows_only_below_ten_unread(
        int $unread,
        int $unreadShown,
    ): void {
        $unreadIds = [];

        for ($day = 1; $day <= $unread; $day++) {
            $unreadIds[] = $this->ownNotice(['created_at' => sprintf('2026-09-%02d 10:00:00+00', $day)])->id;
        }

        $read = $this->ownNotice(['created_at' => '2026-10-09 10:00:00+00', 'read_at' => '2026-10-09 10:00:00+00']);

        $ids = array_column($this->list()->latest($this->contextOf($this->userId)), 'id');

        $expected = array_slice(array_reverse($unreadIds), 0, $unreadShown);
        $this->assertSame($unreadShown < 10 ? [...$expected, $read->id] : $expected, $ids);
    }

    private function list(): NoticeList
    {
        return app(NoticeList::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ownNotice(array $attributes = []): Notice
    {
        return Notice::factory()->createOne(['user_id' => $this->userId, ...$attributes]);
    }

    private function contextOf(int $userId): AccessContext
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($userId, null, null, $today, AccessContext::LOCALE_CODE);
    }
}

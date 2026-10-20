<?php

namespace Tests\Feature\Actions\Notice;

use App\Actions\Notice\MarkAllNoticesRead;
use App\Actions\Notice\MarkNoticeRead;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\TransactionalTestCase;

/**
 * 既読(docs の 設計/ワークフロー/案件の運用/通知.md の 6 節): 自分宛の行だけを既読にする。ゲストは 403、ほかの人の行と無い行は 404。
 * すべて既読は自分宛の未読だけを既読にする。
 */
final class MarkNoticesReadTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
    }

    public function test_marks_an_own_notice_read_and_keeps_the_time_of_a_read_one(): void
    {
        $user = $this->userId('u0001');
        $unread = Notice::factory()->createOne(['user_id' => $user]);
        $read = Notice::factory()->createOne(['user_id' => $user, 'read_at' => '2026-10-01 00:00:00+00']);

        app(MarkNoticeRead::class)->execute($this->contextOf($user), $unread->id);
        app(MarkNoticeRead::class)->execute($this->contextOf($user), $read->id);

        $this->assertSame('2026-10-10T03:00:00+00:00', $unread->refresh()->read_at?->toIso8601String());
        $this->assertSame('2026-10-01T00:00:00+00:00', $read->refresh()->read_at?->toIso8601String());
    }

    public function test_hides_a_notice_of_another_user_and_a_missing_one_as_not_found(): void
    {
        $other = Notice::factory()->createOne(['user_id' => $this->userId('u0002')]);
        $context = $this->contextOf($this->userId('u0001'));

        foreach ([$other->id, $other->id + 1] as $noticeId) {
            try {
                app(MarkNoticeRead::class)->execute($context, $noticeId);
                $this->fail('404 にならなかった');
            } catch (ModelNotFoundException) {
            }
        }

        $this->assertNull($other->refresh()->read_at);
    }

    public function test_forbids_a_guest_to_mark_a_notice_read(): void
    {
        $notice = Notice::factory()->createOne(['user_id' => $this->userId('u0001')]);

        $this->expectException(AuthorizationException::class);

        app(MarkNoticeRead::class)->execute($this->contextOf(null), $notice->id);
    }

    public function test_marks_all_own_unread_notices_read_and_leaves_the_others(): void
    {
        $user = $this->userId('u0001');
        Notice::factory()->count(3)->create(['user_id' => $user]);
        $others = Notice::factory()->count(2)->create(['user_id' => $this->userId('u0002')]);

        app(MarkAllNoticesRead::class)->execute($this->contextOf($user));

        $this->assertSame(0, Notice::query()->where('user_id', $user)->whereNull('read_at')->count());
        $this->assertSame(2, Notice::query()->whereKey($others->modelKeys())->whereNull('read_at')->count());
    }

    public function test_forbids_a_guest_to_mark_all_notices_read(): void
    {
        $this->expectException(AuthorizationException::class);

        app(MarkAllNoticesRead::class)->execute($this->contextOf(null));
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function contextOf(?int $userId): AccessContext
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($userId, null, null, $today, AccessContext::LOCALE_CODE);
    }
}

<?php

namespace Tests\Feature\Meeting;

use App\Enums\Ai\AiRunStatus;
use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\MeetingNoticeType;
use App\Models\Notice\Notice;
use App\Models\Notice\NoticeMute;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の完了の通知も送る口の未読・既読の規則を通る(docs の
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節の「送り手」): 議事録の完了をミュートした関係者には既読で、
 * ほかの関係者(作成者)には未読で作る。議事録は書かれ、実行は成功する。
 */
final class MeetingNoticeReceivingTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    public function test_a_related_party_who_muted_the_completion_gets_a_read_notice(): void
    {
        $creator = $this->user();
        $party = $this->user();
        $values = [Meeting::RelatedParties->value => [['type' => 'user', 'id' => $party->id]]];
        $before = $this->pastedMeeting($creator, values: $values);
        $this->completeMinutes($this->lastRunIdOf($before));
        $this->assertSame([$creator->id, $party->id], $this->recipientsOf($before));

        NoticeMute::query()->create(['user_id' => $party->id, 'type' => MeetingNoticeType::MinutesCreated->value]);
        $after = $this->pastedMeeting($creator, values: $values);
        $run = $this->completeMinutes($this->lastRunIdOf($after));

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame('AI の要約', $this->valuesOf($after)['summary'] ?? null);
        $this->assertSame([$creator->id, $party->id], $this->recipientsOf($after));
        $read = Notice::query()->where('type', MeetingNoticeType::MinutesCreated->value)
            ->where('target_type', 'record')->where('target_id', $after)
            ->whereNotNull('read_at')->pluck('user_id')->all();
        $this->assertSame([$party->id], array_map('intval', $read));
    }

    /**
     * 会議の議事録の完了の通知の宛先(宛先の昇順)。
     *
     * @return list<int>
     */
    private function recipientsOf(int $recordId): array
    {
        $created = array_filter(
            $this->meetingNotices($recordId),
            fn (array $notice): bool => $notice[0] === MeetingNoticeType::MinutesCreated->value,
        );

        return array_values(array_map(fn (array $notice): int => $notice[1], $created));
    }
}

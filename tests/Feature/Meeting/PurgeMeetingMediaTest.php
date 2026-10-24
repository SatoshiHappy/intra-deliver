<?php

namespace Tests\Feature\Meeting;

use App\Actions\Records\Api\PurgeRecordMedia;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Console\Commands\Meeting\PurgeMeetingMedia;
use App\Models\Master\User;
use App\Models\Meeting\Transcript;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use League\Flysystem\UnableToDeleteFile;
use Tests\Support\TransactionalTestCase;

/**
 * 削除予定日の定期の処理(docs の 設計/業務ツール/会議/通知と削除予定日.md の 7.2): 削除予定日が東京の今日より前の会議の音声・動画の実体だけを消し
 * (今日の会議は消さない。書き起こし・添付の行は残る)、1 件の失敗はその会議を飛ばして報告し、最後に失敗で終える。スケジューラが東京の毎日動かす。
 */
final class PurgeMeetingMediaTest extends TransactionalTestCase
{
    use UsesMeetings;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_purges_only_the_audio_of_the_meetings_past_their_purge_date(): void
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $past = $this->meetingDueOn($today->subDay());
        $due = $this->meetingDueOn($today);

        $this->assertSame(0, Artisan::call(PurgeMeetingMedia::class));

        $this->assertSame([true, false], [$this->isPurged($past), $this->isPurged($due)]);
        $this->assertSame(1, RecordAttachment::query()->where('record_id', $past)->count());
        $this->assertTrue(Transcript::query()->where('record_id', $past)->exists());
    }

    public function test_a_stuck_file_after_the_commit_is_reported_and_the_meeting_counts_as_purged(): void
    {
        Exceptions::fake();
        $past = $this->meetingDueOn(CarbonImmutable::today(AccessContext::TIMEZONE)->subDay());
        $this->makeUploadsUndeletable(fn (): bool => true);

        $this->assertSame(0, Artisan::call(PurgeMeetingMedia::class));

        $this->assertTrue($this->isPurged($past));
        $this->assertStringContainsString('1 件消した扱いにした', Artisan::output());
        Exceptions::assertReported(UnableToDeleteFile::class);
    }

    public function test_a_failure_of_one_meeting_does_not_stop_the_others_and_fails_the_command(): void
    {
        $yesterday = CarbonImmutable::today(AccessContext::TIMEZONE)->subDay();
        $failing = $this->meetingDueOn($yesterday);
        $other = $this->meetingDueOn($yesterday);
        $purge = app(FailingPurgeRecordMedia::class);
        $purge->failingRecordIds = [$failing];
        $this->app->instance(PurgeRecordMedia::class, $purge);
        $errors = [];
        Event::listen(MessageLogged::class, function (MessageLogged $logged) use (&$errors): void {
            $errors[] = $logged->level;
        });

        $exitCode = Artisan::call(PurgeMeetingMedia::class);

        $this->assertSame(1, $exitCode);
        $this->assertSame([false, true], [$this->isPurged($failing), $this->isPurged($other)]);
        $this->assertSame(['error'], $errors);
    }

    public function test_the_scheduler_runs_it_every_day_just_after_midnight_in_tokyo(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'meeting:purge-media'),
        ));

        $this->assertSame([['0 0 * * *', 'Asia/Tokyo']], array_map(
            fn (ScheduledEvent $event): array => [
                $event->expression,
                $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : $event->timezone,
            ],
            $events,
        ));
    }

    /** 音声から作り書き起こしのある会議の、削除予定日を直した会議 */
    private function meetingDueOn(CarbonImmutable $date): int
    {
        $recordId = $this->audioMeeting($this->creator);
        $this->completeTranscription($this->lastRunIdOf($recordId), [['発言', 'A', 0, 1]]);
        $input = new RecordInput(['purge_due_on' => $date->toDateString()]);
        app(UpdateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $recordId, $input, null);

        return $recordId;
    }

    private function isPurged(int $recordId): bool
    {
        $uploadId = (int) RecordAttachment::query()->where('record_id', $recordId)->value('upload_id');

        return Upload::query()->findOrFail($uploadId)->purged_at !== null;
    }
}

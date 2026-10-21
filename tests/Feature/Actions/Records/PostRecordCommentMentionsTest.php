<?php

namespace Tests\Feature\Actions\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordComments;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * コメントを書く業務処理 PostRecordComment のうち、宛先と通知(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 2・3 節、
 * 9 節の「宛先」「通知」)。書ける人と本文は PostRecordCommentTest。
 */
final class PostRecordCommentMentionsTest extends TransactionalTestCase
{
    use BuildsRecordComments;
    use CatchesValidationErrors;

    public function test_the_mentions_are_saved_without_duplicates_and_may_include_the_writer(): void
    {
        $fixture = $this->rowAccessFixture();
        ['creator' => $creator, 'attendee' => $attendee, 'member' => $member] = $fixture['people'];
        $mentions = [$attendee->id, $creator->id, $attendee->id, $member->id];

        $id = $this->postComment($creator->id, $fixture['recordId'], mentions: $mentions);

        $this->assertSame(
            [$attendee->id, $creator->id, $member->id],
            RecordCommentMention::query()->where('record_comment_id', $id)->orderBy('id')->pluck('user_id')->all(),
        );
    }

    public function test_up_to_ten_people_are_allowed_and_eleven_are_not(): void
    {
        $notes = $this->recordModule('notes');
        $recordId = Record::factory()->create(['record_module_id' => $notes->id])->id;
        $writer = $this->user();
        $people = array_map(fn () => $this->user()->id, range(1, 11));

        $errors = $this->validationErrors(
            fn () => $this->postComment($writer->id, $recordId, mentions: $people, module: 'notes'),
        );
        $this->assertSame(['mentions' => '宛先は 10 人までです'], $errors);
        $this->assertSame(0, $this->commentsAdded());

        $ten = [...array_slice($people, 0, 10), $people[0]];
        $id = $this->postComment($writer->id, $recordId, mentions: $ten, module: 'notes');
        $this->assertSame(10, RecordCommentMention::query()->where('record_comment_id', $id)->count());
    }

    public function test_every_rejected_mention_gets_the_same_message_without_a_name(): void
    {
        $fixture = $this->rowAccessFixture();
        $ended = $this->user([['2020-01-01', '2026-10-10', false]]);
        $missing = (int) User::query()->max('id') + 1000;
        $mentions = [$fixture['people']['attendee']->id, $fixture['people']['watcher']->id, $ended->id, $missing];

        $errors = $this->validationErrors(
            fn () => $this->postComment($fixture['people']['creator']->id, $fixture['recordId'], mentions: $mentions),
        );

        $this->assertSame(['mentions.1', 'mentions.2', 'mentions.3'], array_keys($errors));
        $this->assertSame(
            array_fill_keys(['mentions.1', 'mentions.2', 'mentions.3'], '宛先に指定できないユーザーが含まれています'),
            $errors,
        );
        $this->assertSame([0, 0], [$this->commentsAdded(), $this->commentNoticesAdded()]);
    }

    public function test_a_body_error_and_a_mention_error_come_back_together(): void
    {
        $fixture = $this->rowAccessFixture();
        ['creator' => $creator, 'watcher' => $watcher] = $fixture['people'];

        $errors = $this->validationErrors(
            fn () => $this->postComment($creator->id, $fixture['recordId'], ' ', [$watcher->id]),
        );

        $this->assertSame(['body', 'mentions.0'], array_keys($errors));
    }

    public function test_a_person_who_can_view_only_with_a_concurrent_department_can_be_mentioned(): void
    {
        [$home, $concurrent, $outside] = array_map(fn () => $this->defaultSetDepartment(), range(1, 3));
        [$module, $recordId] = $this->departmentOnlyModule('notes', $concurrent);
        [$writer, $both, $homeOnly] = [$this->user(), $this->user(), $this->user()];
        $this->join($writer, $concurrent, isMain: true);
        $this->join($both, $home, isMain: true);
        $this->join($both, $concurrent);
        $this->join($homeOnly, $home, isMain: true);
        $this->join($homeOnly, $outside);

        $bothAndHomeOnly = [$both->id, $homeOnly->id];
        $errors = $this->validationErrors(
            fn () => $this->postComment($writer->id, $recordId, mentions: $bothAndHomeOnly, module: $module->code),
        );
        $this->assertSame(['mentions.1'], array_keys($errors));

        $id = $this->postComment($writer->id, $recordId, mentions: [$both->id], module: $module->code);
        $saved = RecordCommentMention::query()->where('record_comment_id', $id)->pluck('user_id')->all();
        $this->assertSame([$both->id], $saved);
    }

    public function test_the_mentioned_except_the_writer_get_one_record_notice_each_with_an_empty_object(): void
    {
        $fixture = $this->rowAccessFixture();
        ['creator' => $creator, 'attendee' => $attendee, 'member' => $member] = $fixture['people'];
        $mentions = [$attendee->id, $creator->id, $member->id, $attendee->id];

        $this->postComment($creator->id, $fixture['recordId'], mentions: $mentions);

        $notices = $this->commentNotices()->orderBy('user_id')->get();
        $this->assertEqualsCanonicalizing([$attendee->id, $member->id], $notices->pluck('user_id')->all());
        $this->assertSame(['records.comment'], $notices->pluck('type')->unique()->values()->all());
        $this->assertSame(['record-comment'], $notices->pluck('target_type')->unique()->values()->all());
        $this->assertSame([$fixture['recordId']], $notices->pluck('target_id')->unique()->values()->all());
        $this->assertSame([$creator->id], $notices->pluck('actor_user_id')->unique()->values()->all());
        $this->assertNull($notices->first()?->read_at);
        $params = DB::table('notices')->whereIn('id', $notices->modelKeys())->orderBy('id')->pluck('params');
        $this->assertSame(['{}', '{}'], $params->map(fn ($value) => (string) $value)->all());
    }

    public function test_no_notice_without_a_mention_or_with_only_the_writer_and_none_after_a_failed_post(): void
    {
        $fixture = $this->rowAccessFixture();
        ['creator' => $creator, 'attendee' => $attendee] = $fixture['people'];

        $this->postComment($creator->id, $fixture['recordId']);
        $this->postComment($creator->id, $fixture['recordId'], mentions: [$creator->id]);
        $this->assertSame(0, $this->commentNoticesAdded());

        $this->validationErrors(
            fn () => $this->postComment($creator->id, $fixture['recordId'], ' ', [$attendee->id]),
        );
        $attendeeNotices = $this->commentNotices()->where('user_id', $attendee->id);
        $this->assertFalse($attendeeNotices->exists());
        $this->assertSame(2, RecordComment::query()->where('record_module_id', $fixture['module']->id)->count());
    }
}

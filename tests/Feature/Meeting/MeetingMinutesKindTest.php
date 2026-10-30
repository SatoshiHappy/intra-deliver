<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\PromptAgent;
use App\Actions\Meeting\MeetingMinutesKind;
use App\Actions\Meeting\RegenerateMinutes;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Meeting\MeetingNoticeReason;
use App\Enums\Meeting\MeetingNoticeType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Master\DepartmentSet;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 議事録の種類の完了と失敗の受け手(docs の 設計/業務ツール/会議/処理.md の 3.4〜3.6、出力の形 = 文字起こしと議事録.md の 5.2、
 * 通知 = 通知と削除予定日.md の 6 節)。外部の AI は呼ばず PromptAgent::fake で応答を作る。
 */
final class MeetingMinutesKindTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    public function test_writes_the_minutes_and_the_title_when_marked_and_notifies_with_the_written_title(): void
    {
        $creator = $this->user();
        $recordId = $this->pastedMeeting($creator);

        $run = $this->completeMinutes($this->lastRunIdOf($recordId));

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame([
            'title' => 'AI の件名', 'summary' => 'AI の要約', 'topics' => "## 話題\n- 要点",
            'decisions' => 'なし', 'open_issues' => 'なし', 'next_actions' => '未定: 確認(未定)',
        ], array_intersect_key($this->valuesOf($recordId), self::OUTPUT));
        $this->assertSame(['AI(議事録の作成)', $creator->id], $this->lastRevisionOf($recordId));
        $this->assertSame(
            [[MeetingNoticeType::MinutesCreated->value, $creator->id, $creator->id, ['title' => 'AI の件名']]],
            $this->meetingNotices($recordId),
        );
    }

    public function test_a_title_given_by_a_person_is_kept(): void
    {
        $recordId = $this->pastedMeeting($this->user(), values: ['title' => '人の件名']);

        $this->completeMinutes($this->lastRunIdOf($recordId));

        $values = $this->valuesOf($recordId);
        $this->assertSame(['人の件名', 'AI の要約'], [$values['title'], $values['summary']]);
        $this->assertSame('人の件名', $this->meetingNotices($recordId)[0][3]['title']);
    }

    /**
     * 再作成は件名を書かず、版の契機と通知の種類が再作成になる。処理中に人が直した欄も上書きする。
     */
    public function test_a_regeneration_overwrites_the_minutes_edited_during_processing_but_not_the_title(): void
    {
        $creator = $this->user();
        $requester = $this->user();
        $party = ['type' => 'user', 'id' => $requester->id];
        $recordId = $this->pastedMeeting($creator, values: ['related_parties' => [$party]]);
        $this->completeMinutes($this->lastRunIdOf($recordId));
        app(RegenerateMinutes::class)->execute($this->contextOf($requester), $recordId, null);
        $edited = new RecordInput(['summary' => '人が直した要約', 'title' => '人が直した件名']);
        app(UpdateRecord::class)->execute($this->contextOf($creator), 'meeting', $recordId, $edited, null);

        $output = [...self::OUTPUT, 'summary' => '再作成の要約', 'title' => '別の件名'];
        $this->completeMinutes($this->lastRunIdOf($recordId), $output);

        $values = $this->valuesOf($recordId);
        $this->assertSame(['人が直した件名', '再作成の要約'], [$values['title'], $values['summary']]);
        $this->assertSame(['AI(議事録の再作成)', $requester->id], $this->lastRevisionOf($recordId));
        $regenerated = array_values(array_filter(
            $this->meetingNotices($recordId),
            fn (array $notice) => $notice[0] === MeetingNoticeType::MinutesRegenerated->value,
        ));
        $this->assertSame(
            [
                [$creator->id, $requester->id, ['title' => '人が直した件名']],
                [$requester->id, $requester->id, ['title' => '人が直した件名']],
            ],
            array_map(fn (array $notice) => [$notice[1], $notice[2], $notice[3]], $regenerated),
        );
    }

    public function test_a_meeting_deleted_before_the_completion_is_not_written_or_notified_and_the_run_succeeds(): void
    {
        $recordId = $this->pastedMeeting($this->user());
        $runId = $this->lastRunIdOf($recordId);
        Record::query()->whereKey($recordId)->delete();

        $run = $this->completeMinutes($runId);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame([], $this->meetingNotices($recordId));
    }

    public function test_a_meeting_without_its_run_row_is_an_inconsistency(): void
    {
        $recordId = $this->pastedMeeting($this->user());
        $runId = $this->lastRunIdOf($recordId);
        MeetingRun::query()->where('record_id', $recordId)->delete();

        $run = $this->completeMinutes($runId);

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$run->status, $run->error_code]);
        $this->assertSame('貼り付けたテキスト', $this->valuesOf($recordId)['title']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function rejectedOutputs(): array
    {
        $output = self::OUTPUT;
        unset($output['decisions']);

        return [
            '項目が無い' => [$output, MeetingMinutesKind::MALFORMED],
            '文字列でない' => [[...self::OUTPUT, 'topics' => ['見出し']], MeetingMinutesKind::MALFORMED],
            '欄が空' => [[...self::OUTPUT, 'open_issues' => " \n"], MeetingMinutesKind::MALFORMED],
            '件名が空' => [[...self::OUTPUT, 'title' => ''], MeetingMinutesKind::MALFORMED],
            '件名に改行' => [[...self::OUTPUT, 'title' => "件名\n続き"], MeetingMinutesKind::MALFORMED],
            '欄が長い' => [[...self::OUTPUT, 'summary' => str_repeat('長', 20_001)], MeetingMinutesKind::TOO_LONG],
            '件名が長い' => [[...self::OUTPUT, 'title' => str_repeat('長', 101)], MeetingMinutesKind::TOO_LONG],
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    #[DataProvider('rejectedOutputs')]
    public function test_an_output_out_of_shape_or_length_fails_and_notifies(array $output, string $reason): void
    {
        $creator = $this->user();
        $recordId = $this->pastedMeeting($creator);

        $runId = $this->lastRunIdOf($recordId);
        PromptAgent::fake([$output]);

        try {
            app(ExecutePrompt::class)->execute($runId);
            $this->fail('完了の処理が失敗しなかった');
        } catch (RunCompletionFailed $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$run->status, $run->error_code]);
        $this->assertArrayNotHasKey('summary', $this->valuesOf($recordId));
        $this->assertSame([[
            MeetingNoticeType::MinutesFailed->value, $creator->id, $creator->id,
            ['title' => '貼り付けたテキスト', 'reason' => MeetingNoticeReason::Other->value],
        ]], $this->meetingNotices($recordId));
    }

    /**
     * 処理中に依頼者の会議の update が外れても議事録を書き、操作した人と通知の起こした人は依頼者のまま(ADR 0029 の決定 6・9)。
     */
    public function test_a_requester_whose_update_was_removed_during_processing_still_gets_the_minutes(): void
    {
        $creator = $this->user();
        $recordId = $this->pastedMeeting($creator);
        $this->denyMeeting(RecordModuleAction::Update);

        $run = $this->completeMinutes($this->lastRunIdOf($recordId));

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertSame('AI の要約', $this->valuesOf($recordId)['summary'] ?? null);
        $this->assertSame(['AI(議事録の作成)', $creator->id], $this->lastRevisionOf($recordId));
        $this->assertSame(
            [[MeetingNoticeType::MinutesCreated->value, $creator->id, $creator->id, ['title' => 'AI の件名']]],
            $this->meetingNotices($recordId),
        );
    }

    /**
     * @return array<string, array{string, string|null, MeetingNoticeReason}>
     */
    public static function failures(): array
    {
        return [
            '出力が切れた' => ['incomplete', FailedRun::OUTPUT_TRUNCATED, MeetingNoticeReason::Truncated],
            '応答を断った' => ['incomplete', FailedRun::CONTENT_REFUSED, MeetingNoticeReason::Other],
            'レコードが見つからない(会議が無ければ通知は出ない。写すのはほか)' => [
                'completion',
                FailedRun::RECORD_NOT_FOUND,
                MeetingNoticeReason::Other,
            ],
            '入れ物の検証で断られた' => ['completion', FailedRun::RECORD_REJECTED, MeetingNoticeReason::RecordUnavailable],
            '形の外れ' => ['completion', MeetingMinutesKind::MALFORMED, MeetingNoticeReason::Other],
            '接続先の誤り' => ['provider', null, MeetingNoticeReason::Other],
            '時間切れ' => ['timeout', null, MeetingNoticeReason::Other],
        ];
    }

    /**
     * 失敗の受け手は対応の行を読まない(種類・依頼者・理由は失敗の値が持つ)。
     */
    #[DataProvider('failures')]
    public function test_the_failure_receiver_notifies_with_the_reason_without_reading_the_run_row(
        string $errorCode,
        ?string $reason,
        MeetingNoticeReason $expected,
    ): void {
        $creator = $this->user();
        $recordId = $this->pastedMeeting($creator);
        MeetingRun::query()->where('record_id', $recordId)->delete();
        $requester = $this->user();

        $failed = new FailedRun(1, MeetingMinutesKind::CODE, $recordId, $requester->id, $errorCode, $reason);
        app(MeetingMinutesKind::class)->failed($failed);

        $this->assertSame(
            [[
                MeetingNoticeType::MinutesFailed->value, $creator->id, $requester->id,
                ['title' => '貼り付けたテキスト', 'reason' => $expected->value],
            ]],
            $this->meetingNotices($recordId),
        );
    }

    public function test_the_failure_receiver_does_nothing_for_a_deleted_or_missing_meeting(): void
    {
        $recordId = $this->pastedMeeting($this->user());
        Record::query()->whereKey($recordId)->delete();

        $requesterId = $this->user()->id;
        $kind = app(MeetingMinutesKind::class);
        $kind->failed(new FailedRun(1, MeetingMinutesKind::CODE, $recordId, $requesterId, 'provider', null));
        $kind->failed(new FailedRun(1, MeetingMinutesKind::CODE, null, $requesterId, 'provider', null));

        $this->assertSame([], $this->meetingNotices($recordId));
    }

    /**
     * 宛先 = 作成者・関係者のユーザー・関係者の組織にその日に所属する人(主所属と兼務。下の組織は含めない)のうち、今日に有効な人。重複を除く。
     */
    public function test_the_recipients_are_the_related_people_on_the_day(): void
    {
        $creator = $this->user();
        $viewer = $this->user();
        $set = DepartmentSet::factory()->create();
        $section = $this->namedDepartment('開発部', $set);
        $child = $this->namedDepartment('開発一課', $set);
        $this->include($section, $child);
        $main = $this->user();
        $concurrent = $this->user();
        $childOnly = $this->user();
        $inactive = $this->user([['2020-01-01', '2020-12-31']]);
        $this->join($main, $section, isMain: true);
        $this->join($concurrent, $section);
        $this->join($childOnly, $child, isMain: true);
        $this->join($inactive, $section, isMain: true);
        $recordId = $this->pastedMeeting($creator, values: [
            'related_parties' => [
                ['type' => 'user', 'id' => $viewer->id],
                ['type' => 'department', 'id' => $section->id],
                ['type' => 'user', 'id' => $creator->id],
            ],
        ]);

        $this->completeMinutes($this->lastRunIdOf($recordId));

        $recipients = array_column($this->meetingNotices($recordId), 1);
        $expected = [$creator->id, $viewer->id, $main->id, $concurrent->id];
        sort($expected);
        $this->assertSame($expected, $recipients);
    }

    /**
     * @return array{string|null, int|null} 最後の版の処理の種別と操作した人
     */
    private function lastRevisionOf(int $recordId): array
    {
        $revision = RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision')->firstOrFail();

        return [$revision->origin_operation, $revision->user_id];
    }
}

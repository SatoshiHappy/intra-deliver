<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\CompletedTranscription;
use App\Actions\Ai\Api\TranscribedSegment;
use App\Actions\Ai\CompletingRun;
use App\Actions\Meeting\MeetingMinutesKind;
use App\Actions\Meeting\MeetingTranscriptionKind;
use App\Actions\Meeting\RegenerateMinutes;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Meeting\MeetingNoticeReason;
use App\Enums\Meeting\MeetingNoticeType;
use App\Enums\Meeting\MeetingProcessingState;
use App\Enums\Meeting\MeetingRunDecline;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Enums\Meeting\TranscriptSource;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Queries\Meeting\MeetingAudioQuery;
use App\Queries\Meeting\MeetingProcessingQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の文字起こしの種類の完了と失敗の受け手(docs の 設計/業務ツール/会議/処理.md の 3.3・3.6、通知 = 通知と削除予定日.md の 6 節):
 * 書き起こし(出どころ = 音声・ファイルの名前・作った実行・区切りの数)と発言の行(空の発言は飛ばす)を書き、依頼者で議事録を依頼して対応(段 = 議事録・
 * 契機と AI が件名を書くかは文字起こしの対応と同じ)を書く。区間が空は失敗、議事録の依頼が断られたら断られた対応と議事録の失敗の通知(書き起こしは残す)。
 * 外部の AI は呼ばず Transcription::fake で応答を作る(音声は Ai の見本)。
 */
final class MeetingTranscriptionKindTest extends TransactionalTestCase
{
    use UsesMeetings;

    private const SEGMENTS = [['はじめます', 'A', 0.5, 1.0], [" \u{3000}", 'B', 1.0, 1.2], ['了解です', 'B', 1.25, 2.0]];

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_writes_the_transcript_and_requests_the_minutes_with_the_untitled_title(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $transcriptionRunId = $this->lastRunIdOf($recordId);

        $run = $this->completeTranscription($transcriptionRunId, self::SEGMENTS);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $transcript = Transcript::query()->where('record_id', $recordId)->sole();
        $this->assertSame(
            [TranscriptSource::Audio, '定例.mp3', (string) $transcriptionRunId, 1],
            [$transcript->source, $transcript->file_name, (string) $transcript->ai_run_id, $transcript->chunk_count],
        );
        $this->assertSame(
            [['A', '0.500', '1.000', 'はじめます'], ['B', '1.250', '2.000', '了解です']],
            $this->segmentsOf($transcript->id),
        );
        $minutes = $this->lastRunOf($recordId);
        $this->assertSame(
            [MeetingRunStage::Minutes, MeetingRunOrigin::Create, true],
            [$minutes->stage, $minutes->origin, $minutes->writes_title],
        );
        $minutesRun = AiRun::query()->findOrFail($minutes->ai_run_id);
        $this->assertSame(
            [MeetingMinutesKind::CODE, $recordId, $this->creator->id],
            [$minutesRun->kind, $minutesRun->record_id, $minutesRun->requester_user_id],
        );
        $userText = $this->userTextOf($minutesRun->id);
        $this->assertStringStartsWith("<資料>\n会議の情報:\n件名: (未入力)\n", $userText);
        $this->assertStringEndsWith("書き起こし:\n[00:00:00] 話者 A: はじめます\n[00:00:01] 話者 B: 了解です\n</資料>", $userText);
    }

    public function test_a_title_given_by_a_person_is_passed_and_kept_unwritten(): void
    {
        $recordId = $this->audioMeeting($this->creator, '定例.mp3', ['title' => '週次の定例']);

        $this->completeTranscription($this->lastRunIdOf($recordId), self::SEGMENTS);

        $this->assertFalse($this->lastRunOf($recordId)->writes_title);
        $this->assertStringStartsWith("<資料>\n会議の情報:\n件名: 週次の定例\n", $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    /**
     * 区切りの数をそのまま書き起こしに残す(完了の受け手を直に呼ぶ)。
     */
    public function test_the_chunk_count_of_the_run_is_kept(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $runId = $this->lastRunIdOf($recordId);
        $segments = [new TranscribedSegment('発言', '2-A', 1500.25, 1501.0)];
        $completed = new CompletedTranscription(
            $runId,
            MeetingTranscriptionKind::CODE,
            $recordId,
            $this->creator->id,
            $segments,
            3,
            2.0,
            $this->contextOf($this->creator),
        );

        $this->completeDirectly($completed);

        $transcript = Transcript::query()->where('record_id', $recordId)->sole();
        $this->assertSame(3, $transcript->chunk_count);
        $this->assertSame([['2-A', '1500.250', '1501.000', '発言']], $this->segmentsOf($transcript->id));
    }

    /**
     * 終わりが始まりより前の区間は、終わりを始まりにそろえて発言を残す(途中に置く。最後の区間は見本の音声の終わりまで届く)。
     */
    public function test_a_segment_ending_before_its_start_is_kept_with_the_end_at_the_start(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $segments = [['はじめます', 'A', 0.5, 1.0], ['逆の区間', 'A', 2.0, 1.5], ['了解です', 'B', 1.25, 2.0]];

        $run = $this->completeTranscription($this->lastRunIdOf($recordId), $segments);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $transcript = Transcript::query()->where('record_id', $recordId)->sole();
        $this->assertSame(
            [['A', '0.500', '1.000', 'はじめます'], ['A', '2.000', '2.000', '逆の区間'], ['B', '1.250', '2.000', '了解です']],
            $this->segmentsOf($transcript->id),
        );
    }

    /**
     * 秒は 0 から列の最大(decimal(10,3))までに収める(完了の受け手を直に呼ぶ)。
     */
    public function test_seconds_are_kept_between_zero_and_the_column_maximum(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $segments = [
            new TranscribedSegment('負の始まり', 'A', -1.5, 0.5),
            new TranscribedSegment('大きすぎる終わり', 'A', 1.0, 12345678.9),
            new TranscribedSegment('丸めると桁を超える', 'A', 9999999.9996, 99999999.0),
        ];
        $completed = new CompletedTranscription(
            $this->lastRunIdOf($recordId),
            MeetingTranscriptionKind::CODE,
            $recordId,
            $this->creator->id,
            $segments,
            1,
            2.0,
            $this->contextOf($this->creator),
        );

        $this->completeDirectly($completed);

        $transcript = Transcript::query()->where('record_id', $recordId)->sole();
        $this->assertSame(
            [
                ['A', '0.000', '0.500', '負の始まり'],
                ['A', '1.000', '9999999.999', '大きすぎる終わり'],
                ['A', '9999999.999', '9999999.999', '丸めると桁を超える'],
            ],
            $this->segmentsOf($transcript->id),
        );
    }

    /**
     * @return array<string, array{list<array{string, string, float, float}>}>
     */
    public static function emptySegments(): array
    {
        return ['区間が 0' => [[]], 'すべて空' => [[[' ', 'A', 0.0, 1.0], ["\n", 'B', 1.0, 2.0]]]];
    }

    /**
     * @param  list<array{string, string, float, float}>  $segments
     */
    #[DataProvider('emptySegments')]
    public function test_an_empty_transcript_fails_and_notifies_that_the_audio_was_unusable(array $segments): void
    {
        $recordId = $this->audioMeeting($this->creator);

        $run = $this->completeTranscription($this->lastRunIdOf($recordId), $segments);

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$run->status, $run->error_code]);
        $this->assertFalse(Transcript::query()->where('record_id', $recordId)->exists());
        $this->assertSame([[
            MeetingNoticeType::TranscriptionFailed->value, $this->creator->id, $this->creator->id,
            ['title' => '定例.mp3', 'reason' => MeetingNoticeReason::AudioUnusable->value],
        ]], $this->meetingNotices($recordId));
    }

    /**
     * 処理中に会議を消すと(添付を外してから消す。音声の実体は残る)、完了しても何も書かず通知も送らない。
     */
    public function test_a_meeting_deleted_before_the_completion_is_not_written_or_notified(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $runId = $this->lastRunIdOf($recordId);
        RecordAttachment::query()->where('record_id', $recordId)->delete();
        Record::query()->whereKey($recordId)->delete();
        $runs = MeetingRun::query()->count();

        $run = $this->completeTranscription($runId, self::SEGMENTS);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertFalse(Transcript::query()->where('record_id', $recordId)->exists());
        $this->assertSame($runs, MeetingRun::query()->count());
        $this->assertSame([], $this->meetingNotices($recordId));
    }

    public function test_a_meeting_without_its_run_row_is_an_inconsistency(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $runId = $this->lastRunIdOf($recordId);
        MeetingRun::query()->where('record_id', $recordId)->delete();

        $run = $this->completeTranscription($runId, self::SEGMENTS);

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$run->status, $run->error_code]);
        $this->assertFalse(Transcript::query()->where('record_id', $recordId)->exists());
    }

    /**
     * 完了の受け手を直に呼ぶ(実行を処理中にし、完了中の実行の印を付ける = 読む口の呼べる時)。
     */
    private function completeDirectly(CompletedTranscription $completed): void
    {
        AiRun::query()->whereKey($completed->runId)->update(['status' => AiRunStatus::Running, 'started_at' => now()]);
        app(CompletingRun::class)->during(
            $completed->runId,
            fn () => app(MeetingTranscriptionKind::class)->complete($completed),
        );
    }

    /**
     * @return array<string, array{string, MeetingRunDecline}>
     */
    public static function declinedMinutes(): array
    {
        return ['上限' => ['budget', MeetingRunDecline::Budget], '長すぎる' => ['input', MeetingRunDecline::TooLong]];
    }

    /**
     * 議事録の依頼が断られたら、断られた対応と議事録の失敗の通知を書き、書き起こしは残す(文字起こしの実行は成功)。
     */
    #[DataProvider('declinedMinutes')]
    public function test_declined_minutes_keep_the_transcript_and_write_a_declined_run(
        string $limit,
        MeetingRunDecline $decline,
    ): void {
        $recordId = $this->audioMeeting($this->creator);

        if ($limit === 'budget') {
            config(['ai_runs.budget.default.daily_usd' => 0.000001]);
        } else {
            $kinds = (array) config('ai_runs.kinds');
            $kinds[MeetingMinutesKind::CODE] = [...(array) $kinds[MeetingMinutesKind::CODE], 'max_input_chars' => 10];
            config(['ai_runs.kinds' => $kinds]);
        }

        $run = $this->completeTranscription($this->lastRunIdOf($recordId), self::SEGMENTS);

        $this->assertSame(AiRunStatus::Succeeded, $run->status);
        $this->assertTrue(Transcript::query()->where('record_id', $recordId)->exists());
        $declined = $this->lastRunOf($recordId);
        $this->assertSame(
            [null, MeetingRunStage::Minutes, MeetingRunOrigin::Create, true, $decline, $this->creator->id],
            [
                $declined->ai_run_id, $declined->stage, $declined->origin, $declined->writes_title,
                $declined->declined_code, $declined->requester_user_id,
            ],
        );
        $this->assertSame([[
            MeetingNoticeType::MinutesFailed->value, $this->creator->id, $this->creator->id,
            ['title' => '定例.mp3', 'reason' => $decline->value],
        ]], $this->meetingNotices($recordId));
        $this->assertSame(
            [$recordId => MeetingProcessingState::Failed],
            app(MeetingProcessingQuery::class)->statesOf([$recordId]),
        );
    }

    /**
     * 再作成の文字起こしの完了は、契機(再作成)と AI が件名を書かないことを議事録の対応に写す。仮の件名のままの会議は「(未入力)」で送る。
     */
    public function test_a_regeneration_copies_its_origin_and_sends_the_provisional_title_as_untitled(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $this->completeTranscription($this->lastRunIdOf($recordId), []);
        app(RegenerateMinutes::class)->execute($this->contextOf($this->creator), $recordId, null);

        $this->completeTranscription($this->lastRunIdOf($recordId), self::SEGMENTS);

        $minutes = $this->lastRunOf($recordId);
        $this->assertSame(
            [MeetingRunStage::Minutes, MeetingRunOrigin::Regenerate, false],
            [$minutes->stage, $minutes->origin, $minutes->writes_title],
        );
        $this->assertStringStartsWith("<資料>\n会議の情報:\n件名: (未入力)\n", $this->userTextOf((int) $minutes->ai_run_id));
    }

    /**
     * 処理中に音声の添付が外されたら、書き起こしのファイルの名前は「音声」。
     */
    public function test_the_file_name_is_audio_when_the_attachment_was_removed(): void
    {
        $recordId = $this->audioMeeting($this->creator, '定例.mp3', ['title' => '週次の定例']);
        RecordAttachment::query()->where('record_id', $recordId)->delete();

        $this->completeTranscription($this->lastRunIdOf($recordId), self::SEGMENTS);

        $this->assertSame(
            MeetingAudioQuery::UNKNOWN_FILE_NAME,
            Transcript::query()->where('record_id', $recordId)->value('file_name'),
        );
    }
}

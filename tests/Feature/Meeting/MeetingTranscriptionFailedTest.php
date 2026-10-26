<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Meeting\MeetingTranscriptionKind;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Meeting\MeetingNoticeReason;
use App\Enums\Meeting\MeetingNoticeType;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の文字起こしの種類の失敗の受け手(docs の 設計/業務ツール/会議/処理.md の 3.6、理由の文の分け方 = 通知と削除予定日.md の 6.4):
 * 会議があれば文字起こしの失敗の通知を送る(出力が切れた・区間が空・音声の変換の失敗・実体が無い = 音声から書き起こせない、会議に書けない・読めない、ほか)。
 * 会議が無ければ何もしない。
 */
final class MeetingTranscriptionFailedTest extends TransactionalTestCase
{
    use UsesMeetings;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    /**
     * 変換の失敗・実体が無いを受け手が写す(音声の実体を消してから動かす)。
     */
    public function test_a_missing_audio_file_fails_and_notifies_that_the_audio_was_unusable(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        Storage::disk('local')->deleteDirectory('uploads');

        $run = $this->completeTranscription($this->lastRunIdOf($recordId), [['発言', 'A', 0, 1]]);

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Conversion], [$run->status, $run->error_code]);
        $this->assertSame([[
            MeetingNoticeType::TranscriptionFailed->value, $this->creator->id, $this->creator->id,
            ['title' => '定例.mp3', 'reason' => MeetingNoticeReason::AudioUnusable->value],
        ]], $this->meetingNotices($recordId));
    }

    /**
     * @return array<string, array{string, string|null, MeetingNoticeReason}>
     */
    public static function failures(): array
    {
        return [
            '出力が切れた' => ['incomplete', FailedRun::OUTPUT_TRUNCATED, MeetingNoticeReason::AudioUnusable],
            '区間が空' => ['completion', MeetingTranscriptionKind::TRANSCRIPT_EMPTY, MeetingNoticeReason::AudioUnusable],
            '変換の失敗・実体が無い' => ['conversion', null, MeetingNoticeReason::AudioUnusable],
            '入れ物の検証で断られた' => ['completion', FailedRun::RECORD_REJECTED, MeetingNoticeReason::RecordUnavailable],
            'レコードが見つからない(会議が無ければ通知は出ない。写すのはほか)' => [
                'completion',
                FailedRun::RECORD_NOT_FOUND,
                MeetingNoticeReason::Other,
            ],
            '接続先の誤り' => ['provider', null, MeetingNoticeReason::Other],
            '時間切れ' => ['timeout', null, MeetingNoticeReason::Other],
            'worker が落ちた' => ['worker_lost', null, MeetingNoticeReason::Other],
        ];
    }

    #[DataProvider('failures')]
    public function test_the_failure_receiver_notifies_the_transcription_failure_with_the_reason(
        string $errorCode,
        ?string $reason,
        MeetingNoticeReason $expected,
    ): void {
        $recordId = $this->audioMeeting($this->creator);
        $requester = $this->user();

        $failed = new FailedRun(1, MeetingTranscriptionKind::CODE, $recordId, $requester->id, $errorCode, $reason);
        app(MeetingTranscriptionKind::class)->failed($failed);

        $this->assertSame([[
            MeetingNoticeType::TranscriptionFailed->value, $this->creator->id, $requester->id,
            ['title' => '定例.mp3', 'reason' => $expected->value],
        ]], $this->meetingNotices($recordId));
    }

    public function test_the_failure_receiver_does_nothing_for_a_deleted_or_missing_meeting(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        RecordAttachment::query()->where('record_id', $recordId)->delete();
        Record::query()->whereKey($recordId)->delete();
        $kind = app(MeetingTranscriptionKind::class);

        $code = MeetingTranscriptionKind::CODE;

        $kind->failed(new FailedRun(1, $code, $recordId, $this->creator->id, 'provider', null));
        $kind->failed(new FailedRun(1, $code, null, $this->creator->id, 'provider', null));

        $this->assertSame([], $this->meetingNotices($recordId));
    }
}

<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Meeting\MeetingMinutesKind;
use App\Enums\Meeting\MeetingNoticeReason;
use App\Enums\Meeting\MeetingNoticeType;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\Record;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の議事録の種類の失敗の受け手(docs の 設計/業務ツール/会議/処理.md の 3.6、理由の文の分け方 = 通知と削除予定日.md の 6.4)。
 */
final class MeetingMinutesFailedTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
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
            '長さの外れ' => ['completion', MeetingMinutesKind::TOO_LONG, MeetingNoticeReason::Truncated],
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
}

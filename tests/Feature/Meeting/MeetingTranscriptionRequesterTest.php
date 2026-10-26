<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\ReadRecordForRun;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\MeetingNoticeType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Meeting\Transcript;
use App\Models\Records\Record;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の文字起こしの完了の中の依頼者(docs の 設計/業務ツール/会議/処理.md の 3.11、文字起こしと議事録.md の 5.3、ADR 0029): 処理中に依頼者が
 * 会議を読めなくなった・更新できなくなった・無効になっても議事録まで書かれ、材料の会議の欄は AI の読む口の値だけから作る。
 * 種類の完了と失敗の受け手のほかの確かめは MeetingTranscriptionKindTest。
 */
final class MeetingTranscriptionRequesterTest extends TransactionalTestCase
{
    use UsesMeetings;

    private const SEGMENTS = [['はじめます', 'A', 0.5, 1.0], ['了解です', 'B', 1.25, 2.0]];

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    /**
     * 処理中に依頼者が会議を読めなくなった・更新できなくなった・無効になっても、書き起こしを書いて議事録を依頼し、議事録も書かれる。
     * 更新者と完了の通知の起こした人は依頼者のまま(docs の 設計/業務ツール/会議/処理.md の 3.11、ADR 0029)。通知の宛先は完了の時点に
     * 会議を閲覧できる関係者(通知と削除予定日.md の 6.2): read を外すと誰も閲覧できず宛先が無く、無効になった依頼者には届かない。
     *
     * @param  'read'|'update'|'inactive'  $lost
     */
    #[DataProvider('lostDuringProcessing')]
    public function test_the_minutes_are_written_after_the_requester_lost_access_during_processing(string $lost): void
    {
        $viewer = $this->user();
        $recordId = $this->audioMeeting($this->creator, '定例.mp3', [Meeting::ViewerUsers->value => [$viewer->id]]);
        match ($lost) {
            'read' => $this->denyMeeting(RecordModuleAction::Read),
            'update' => $this->denyMeeting(RecordModuleAction::Update),
            'inactive' => UserTerm::query()->where('user_id', $this->creator->id)->update(['end_date' => '2021-01-01']),
        };

        $transcription = $this->completeTranscription($this->lastRunIdOf($recordId), self::SEGMENTS);
        $minutes = $this->completeMinutes($this->lastRunIdOf($recordId));

        $this->assertSame([AiRunStatus::Succeeded, AiRunStatus::Succeeded], [$transcription->status, $minutes->status]);
        $this->assertTrue(Transcript::query()->where('record_id', $recordId)->exists());
        $this->assertSame([self::OUTPUT['summary'], $this->creator->id], [
            $this->valuesOf($recordId)['summary'] ?? null,
            Record::query()->findOrFail($recordId)->updated_by_user_id,
        ]);
        $created = MeetingNoticeType::MinutesCreated->value;
        $recipients = match ($lost) {
            'read' => [],
            'update' => [$this->creator->id, $viewer->id],
            'inactive' => [$viewer->id],
        };
        $this->assertSame(
            array_map(fn (int $recipient) => [$created, $recipient, $this->creator->id], $recipients),
            array_map(fn (array $notice) => array_slice($notice, 0, 3), $this->meetingNotices($recordId)),
        );
    }

    /**
     * @return array<string, array{'read'|'update'|'inactive'}>
     */
    public static function lostDuringProcessing(): array
    {
        return ['会議の read を外した' => ['read'], '会議の update を外した' => ['update'], '依頼者が無効になった' => ['inactive']];
    }

    /**
     * 議事録の材料の会議の欄は、AI の読む口の値だけから作る(依頼者の判定する読み取りを通らない = 文字起こしと議事録.md の 5.3)。
     */
    public function test_the_materials_come_only_from_the_read_port(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $runId = $this->lastRunIdOf($recordId);
        $values = [Meeting::Agenda->value => '読む口の議題', Meeting::Notes->value => '読む口のメモ'];
        $read = ['module' => [], 'record' => ['values' => $values]];
        $this->mock(ReadRecordForRun::class, fn (MockInterface $port) => $port
            ->shouldReceive('execute')->once()->with($runId)->andReturn($read));
        $this->mock(RecordViewAccess::class, fn (MockInterface $access) => $access->shouldNotReceive('canView'));
        $this->partialMock(RecordDisplayQuery::class, fn (MockInterface $records) => $records
            ->shouldNotReceive('show'));

        $this->completeTranscription($runId, self::SEGMENTS);

        $userText = $this->userTextOf($this->lastRunIdOf($recordId));
        $this->assertStringContainsString("アジェンダ:\n読む口の議題\n\n議事メモ:\n読む口のメモ", $userText);
    }
}

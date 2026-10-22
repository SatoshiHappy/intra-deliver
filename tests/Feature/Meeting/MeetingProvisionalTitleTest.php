<?php

namespace Tests\Feature\Meeting;

use App\Actions\Meeting\RegenerateMinutes;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Models\Master\User;
use App\Models\Meeting\Transcript;
use App\Models\Records\RecordAttachment;
use App\Queries\Meeting\MeetingAudioQuery;
use Tests\Support\TransactionalTestCase;

/**
 * 音声から作った会議の、議事録の依頼に渡す件名(docs の 設計/業務ツール/会議/文字起こしと議事録.md の 5.3、仮の件名 = README.md の 1.2):
 * 仮の値は作成で取り込んだ音声の名前で、再作成で別の音声から作った書き起こしの名前は使わない。作成で取り込んだ音声の名前が分からない
 * (書き起こしの前に外した)会議は、人が直した件名も渡さない。外部の AI は呼ばず Transcription::fake・PromptAgent::fake で応答を作る。
 */
final class MeetingProvisionalTitleTest extends TransactionalTestCase
{
    use UsesMeetings;

    private const SEGMENTS = [['はじめます', 'A', 0.5, 1.0], ['了解です', 'B', 1.25, 2.0]];

    private const UNTITLED = "会議の情報:\n件名: (未入力)\n";

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    /**
     * 作成の文字起こしが失敗し、別の音声を足して再作成した会議は、件名が取り込んだ名前のままなら渡さない。
     */
    public function test_a_title_left_as_the_imported_name_is_not_passed_after_regenerating_from_another_audio(): void
    {
        $recordId = $this->failedAudioMeeting();
        $this->attach($recordId, '二回目.wav');

        $this->regenerateFromAudio($recordId);

        $this->assertStringStartsWith(self::UNTITLED, $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    /**
     * 作成の文字起こしが書いた書き起こしの名前は、後で音声を外しても仮の値に使う。
     */
    public function test_the_transcript_of_the_create_keeps_the_imported_name_after_the_audio_is_removed(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $this->completeWithMinutesDeclined($this->lastRunIdOf($recordId));
        RecordAttachment::query()->where('record_id', $recordId)->delete();

        app(RegenerateMinutes::class)->execute($this->contextOf($this->creator), $recordId, null);

        $this->assertStringStartsWith(self::UNTITLED, $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    /**
     * 作成の文字起こしが書いた書き起こしの名前と違う件名(人が直した件名)は、音声を外した後も渡す。
     */
    public function test_an_edited_title_is_passed_with_the_transcript_of_the_create(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $this->completeWithMinutesDeclined($this->lastRunIdOf($recordId));
        $this->editTitle($recordId, '10 月の定例会');
        RecordAttachment::query()->where('record_id', $recordId)->delete();

        app(RegenerateMinutes::class)->execute($this->contextOf($this->creator), $recordId, null);

        $this->assertStringStartsWith(
            "会議の情報:\n件名: 10 月の定例会\n",
            $this->userTextOf($this->lastRunIdOf($recordId)),
        );
    }

    /**
     * 取り込んだ音声を外して別の音声で再作成した会議は、仮の値が分からないので、人が直した件名も渡さない。
     */
    public function test_an_edited_title_is_not_passed_when_the_imported_audio_was_removed(): void
    {
        $recordId = $this->failedAudioMeeting();
        $this->editTitle($recordId, '10 月の定例会');
        RecordAttachment::query()->where(['record_id' => $recordId, 'name' => '定例.mp3'])->delete();
        $this->attach($recordId, '二回目.wav');

        $this->regenerateFromAudio($recordId);

        $this->assertStringStartsWith(self::UNTITLED, $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    /**
     * 取り込んだ音声が残っていれば仮の値が分かるので、人が直した件名を渡す。
     */
    public function test_an_edited_title_is_passed_while_the_imported_audio_is_attached(): void
    {
        $recordId = $this->failedAudioMeeting();
        $this->editTitle($recordId, '10 月の定例会');
        $this->attach($recordId, '二回目.wav');

        $this->regenerateFromAudio($recordId);

        $this->assertStringStartsWith(
            "会議の情報:\n件名: 10 月の定例会\n",
            $this->userTextOf($this->lastRunIdOf($recordId)),
        );
    }

    /**
     * 作成の文字起こしの完了の前に音声を外した会議(書き起こしの名前 = 固定の名前)は、仮の値が分からないので件名を渡さない。
     */
    public function test_a_transcript_with_the_unknown_file_name_is_treated_as_provisional(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        RecordAttachment::query()->where('record_id', $recordId)->delete();
        $this->completeWithMinutesDeclined($this->lastRunIdOf($recordId));
        $this->assertSame(
            MeetingAudioQuery::UNKNOWN_FILE_NAME,
            Transcript::query()->where('record_id', $recordId)->value('file_name'),
        );

        app(RegenerateMinutes::class)->execute($this->contextOf($this->creator), $recordId, null);

        $this->assertStringStartsWith(self::UNTITLED, $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    /** 「定例.mp3」から作り、文字起こしが区間 0 で失敗した会議(書き起こしが無い) */
    private function failedAudioMeeting(): int
    {
        $recordId = $this->audioMeeting($this->creator);
        $this->completeTranscription($this->lastRunIdOf($recordId), []);

        return $recordId;
    }

    /**
     * 文字起こしを成功させ、続く議事録の依頼を議事録の区分の上限で断らせる(書き起こしは残る)。
     */
    private function completeWithMinutesDeclined(int $runId): void
    {
        config(['ai_runs.budget.default.daily_usd' => 0.000001]);
        $this->completeTranscription($runId, self::SEGMENTS);
        config(['ai_runs.budget.default.daily_usd' => null]);
    }

    /**
     * 音声から再作成し、その文字起こしを完了させる(続けて議事録を依頼する)。
     */
    private function regenerateFromAudio(int $recordId): void
    {
        app(RegenerateMinutes::class)->execute($this->contextOf($this->creator), $recordId, null);
        $this->completeTranscription($this->lastRunIdOf($recordId), self::SEGMENTS);
    }

    private function attach(int $recordId, string $name): void
    {
        $input = new RecordInput(attachFiles: [$this->audioFile($name, 'pcm_s16le.wav')]);
        app(UpdateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $recordId, $input, null);
    }

    private function editTitle(int $recordId, string $title): void
    {
        $input = new RecordInput(['title' => $title]);
        app(UpdateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $recordId, $input, null);
    }
}

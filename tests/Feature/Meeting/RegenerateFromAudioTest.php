<?php

namespace Tests\Feature\Meeting;

use App\Actions\Meeting\MeetingAudioRequest;
use App\Actions\Meeting\RegenerateMinutes;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Jobs\Ai\RunPromptJob;
use App\Jobs\Ai\RunTranscriptionJob;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Queries\Meeting\MeetingRegenerableQuery;
use App\Queries\Meeting\MeetingTitleQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 書き起こしの無い会議の、音声からの再作成(docs の 設計/業務ツール/会議/処理.md の 3.7・3.9): 会議の今の添付のうち受ける形式で実体のある
 * 最も新しい音声で文字起こしを依頼し、対応(段 = 文字起こし・契機 = 再作成・AI は件名を書かない)を書く。追加の指示は受けない。受ける音声が無ければ
 * 「書き起こしと音声が無い」。手で作った会議は、受ける音声を付けても押せる会議でない。音声の依頼の判定(閲覧・会議の今の添付)と、
 * 書き起こしの無い音声の会議の仮の件名も確かめる。
 */
final class RegenerateFromAudioTest extends TransactionalTestCase
{
    use UsesMeetings;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_requests_the_transcription_of_the_latest_accepted_audio(): void
    {
        $recordId = $this->failedAudioMeeting();
        $this->attach($recordId, $this->audioFile('二回目.wav', 'pcm_s16le.wav'));
        $this->attach($recordId, UploadedFile::fake()->createWithContent('録画.mp4', $this->sampleContent('mp4')));
        $latestAudio = (int) RecordAttachment::query()->where(['record_id' => $recordId, 'name' => '二回目.wav'])
            ->value('upload_id');

        $this->regenerate($recordId, [])->assertStatus(303)->assertSessionHasNoErrors();

        $run = $this->lastRunOf($recordId);
        $this->assertSame(
            [MeetingRunStage::Transcription, MeetingRunOrigin::Regenerate, false],
            [$run->stage, $run->origin, $run->writes_title],
        );
        $aiRun = AiRun::query()->findOrFail($run->ai_run_id);
        $this->assertSame(['meeting.transcription', $latestAudio], [$aiRun->kind, $aiRun->input_upload_id]);
    }

    public function test_instructions_are_not_accepted_without_a_transcript(): void
    {
        $recordId = $this->failedAudioMeeting();
        $runs = MeetingRun::query()->count();

        $this->regenerate($recordId, ['instructions' => '短く'])
            ->assertSessionHasErrors(['instructions' => RegenerateMinutes::INSTRUCTIONS_WITHOUT_TRANSCRIPT]);

        $this->assertSame($runs, MeetingRun::query()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function withoutAcceptedAudio(): array
    {
        return ['期限で消えた' => ['purged'], '外した' => ['removed'], 'ほかの形式だけ' => ['video']];
    }

    #[DataProvider('withoutAcceptedAudio')]
    public function test_without_an_accepted_audio_there_is_nothing_to_regenerate_from(string $case): void
    {
        $recordId = $this->failedAudioMeeting();
        $attachments = RecordAttachment::query()->where('record_id', $recordId);

        if ($case === 'purged') {
            Upload::query()->whereIn('id', $attachments->pluck('upload_id'))
                ->update(['purged_at' => now(), 'purge_due_on' => '2026-01-01']);
        } else {
            $attachments->delete();
        }

        if ($case === 'video') {
            $this->attach($recordId, UploadedFile::fake()->createWithContent('録画.mp4', $this->sampleContent('mp4')));
        }

        $this->regenerate($recordId, [])->assertSessionHasErrors(['meeting' => MeetingRegenerableQuery::NO_MATERIAL]);
    }

    /**
     * 音声も無ければ、追加の指示があっても材料が無いことを返す(追加の指示の誤りにしない)。
     */
    public function test_without_a_transcript_or_an_audio_the_instructions_are_not_the_error(): void
    {
        $recordId = $this->failedAudioMeeting();
        RecordAttachment::query()->where('record_id', $recordId)->delete();

        $this->regenerate($recordId, ['instructions' => '短く'])
            ->assertSessionHasErrors(['meeting' => MeetingRegenerableQuery::NO_MATERIAL])
            ->assertSessionDoesntHaveErrors('instructions');
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function manualMeetingInputs(): array
    {
        return ['追加の指示なし' => [[]], '追加の指示つき' => [['instructions' => '短く']]];
    }

    /**
     * 手で作った会議(書き起こしも対応も無い)は、受ける形式の音声を付けても再作成できない(対応・実行・ジョブを作らない)。
     *
     * @param  array<string, string>  $input
     */
    #[DataProvider('manualMeetingInputs')]
    public function test_a_manual_meeting_with_an_accepted_audio_cannot_be_regenerated(array $input): void
    {
        $values = new RecordInput(['title' => '手で作った']);
        $recordId = app(CreateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $values);
        $this->attach($recordId, $this->audioFile('定例.mp3'));
        $runs = [MeetingRun::query()->count(), AiRun::query()->count()];

        $this->regenerate($recordId, $input)
            ->assertSessionHasErrors(['meeting' => RegenerateMinutes::NOT_FROM_AUDIO])
            ->assertSessionDoesntHaveErrors('instructions');

        $this->assertSame($runs, [MeetingRun::query()->count(), AiRun::query()->count()]);
        Queue::assertNotPushed(RunTranscriptionJob::class);
        Queue::assertNotPushed(RunPromptJob::class);
    }

    public function test_the_minutes_budget_is_checked_before_the_transcription(): void
    {
        $recordId = $this->failedAudioMeeting();
        config(['ai_runs.budget.default.daily_usd' => 0.000001]);

        $this->regenerate($recordId, [])
            ->assertSessionHasErrors(['ai' => '今日の AI の利用の上限に近いため、音声から作り直せません。']);
    }

    /**
     * 再作成の文字起こしの依頼が断られたときは、作成の画面の文を添えない。
     */
    public function test_a_declined_transcription_is_not_given_the_guide_of_the_create_dialog(): void
    {
        $recordId = $this->failedAudioMeeting();
        config(['ai_runs.budget.transcription.daily_usd' => 0.000001]);

        $this->regenerate($recordId, [])->assertSessionHasErrors(['ai' => '今日の音声の取り込みの上限に達しました。']);
    }

    public function test_a_meeting_in_processing_cannot_be_regenerated_from_the_audio(): void
    {
        $recordId = $this->audioMeeting($this->creator);

        $this->regenerate($recordId, [])->assertSessionHasErrors(['meeting' => RegenerateMinutes::PROCESSING]);
    }

    /**
     * 音声の依頼の判定: 閲覧できない依頼者は 404 の例外、ほかの会議の添付の実体は呼び手の誤り。
     */
    public function test_the_audio_request_checks_the_viewer_and_the_attachment_of_the_meeting(): void
    {
        $recordId = $this->failedAudioMeeting();
        $other = $this->audioMeeting($this->creator, '別の会議.mp3');
        $ownUpload = (int) RecordAttachment::query()->where('record_id', $recordId)->value('upload_id');
        $otherUpload = (int) RecordAttachment::query()->where('record_id', $other)->value('upload_id');
        $request = app(MeetingAudioRequest::class);
        $origin = MeetingRunOrigin::Regenerate;

        try {
            $request->execute($this->contextOf($this->user()), $recordId, $ownUpload, $origin, false);
            $this->fail('閲覧できない依頼者が依頼できた');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $request->execute($this->contextOf($this->creator), $recordId, $otherUpload, $origin, false);
    }

    /**
     * 書き起こしの無い音声の会議の仮の件名 = 作成の文字起こしの実行の入力の音声の、今の添付の名前(外されていれば名前が分からないので仮とみなす)。
     */
    public function test_the_provisional_title_of_an_audio_meeting_without_a_transcript_is_the_audio_name(): void
    {
        $recordId = $this->failedAudioMeeting();
        $titles = app(MeetingTitleQuery::class);
        $input = new RecordInput(['title' => '定例.mp3']);
        $manual = app(CreateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $input);

        $this->assertTrue($titles->isProvisional($recordId, '定例.mp3'));
        $this->assertFalse($titles->isProvisional($recordId, '直した件名'));
        $this->assertFalse($titles->isProvisional($manual, '定例.mp3'));

        RecordAttachment::query()->where('record_id', $recordId)->delete();
        $this->assertTrue($titles->isProvisional($recordId, '定例.mp3'));
    }

    /** 音声から作り、文字起こしが区間 0 で失敗した会議(書き起こしが無い) */
    private function failedAudioMeeting(): int
    {
        $recordId = $this->audioMeeting($this->creator);
        $this->completeTranscription($this->lastRunIdOf($recordId), []);

        return $recordId;
    }

    private function attach(int $recordId, UploadedFile $file): void
    {
        $input = new RecordInput(attachFiles: [$file]);
        app(UpdateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $recordId, $input, null);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return TestResponse<Response>
     */
    private function regenerate(int $recordId, array $input): TestResponse
    {
        return $this->actingAs($this->creator)->from("/records/data/meeting/{$recordId}")
            ->post("/meetings/{$recordId}/regenerate", $input);
    }
}

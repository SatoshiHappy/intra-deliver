<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\RunRequestMessages;
use App\Actions\Meeting\TextImportReader;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Enums\Records\RecordModuleAction;
use App\Http\Controllers\Web\Meeting\MeetingController;
use App\Models\Ai\AiRun;
use App\Models\Meeting\Transcript;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 音声からの会議の作成(docs の 設計/業務ツール/会議/処理.md の 3.2・3.9、画面の文 = 公開デモと画面.md の 9.2): 拡張子(大文字小文字を区別しない)で
 * 音声と決め、会議を作って同じ登録で音声を添付に付け、文字起こしを依頼して会議と実行の対応(段 = 文字起こし・契機 = 作成)を書く。
 * 議事録の区分の先の確かめ・文字起こしの依頼の 422・PHP の上限で落ちた音声・添付ファイルを使用を外した業務モジュールでは、会議も添付も対応も残らない。
 */
final class CreateMeetingFromAudioTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function audios(): array
    {
        return [
            'mp3' => ['定例.mp3', 'mp3float.mp3'],
            'm4a' => ['定例.m4a', 'aac.m4a'],
            'wav' => ['定例.wav', 'pcm_s16le.wav'],
            '大文字の拡張子' => ['定例.MP3', 'mp3float.mp3'],
        ];
    }

    #[DataProvider('audios')]
    public function test_creates_a_meeting_with_the_audio_attached_and_requests_the_transcription(
        string $name,
        string $sample,
    ): void {
        $user = $this->user();
        $file = $this->audioFile($name, $sample);

        $this->actingAs($user)->from('/records/data/meeting')->post('/meetings', ['file' => $file])
            ->assertStatus(303)->assertRedirect('/records/data/meeting')->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => MeetingController::CREATED]);

        $recordId = $this->latestMeetingId();
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->sole();
        $run = $this->lastRunOf($recordId);
        $aiRun = AiRun::query()->findOrFail($run->ai_run_id);
        $this->assertSame([$name, $name], [$this->valuesOf($recordId)['title'], $attachment->name]);
        $this->assertSame(
            [MeetingRunStage::Transcription, MeetingRunOrigin::Create, true],
            [$run->stage, $run->origin, $run->writes_title],
        );
        $this->assertSame(
            ['meeting.transcription', $recordId, $attachment->upload_id, $user->id, 'transcription'],
            [$aiRun->kind, $aiRun->record_id, $aiRun->input_upload_id, $aiRun->requester_user_id, $aiRun->budget],
        );
        $this->assertFalse(Transcript::query()->where('record_id', $recordId)->exists());
    }

    public function test_a_title_given_by_a_person_is_kept_and_the_ai_does_not_write_it(): void
    {
        $recordId = $this->audioMeeting($this->user(), '定例.mp3', ['title' => '週次の定例']);

        $this->assertFalse($this->lastRunOf($recordId)->writes_title);
        $this->assertSame('週次の定例', $this->valuesOf($recordId)['title']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function minutesBudgets(): array
    {
        $day = ['daily_usd' => 0.000001, 'monthly_usd' => null, 'total_usd' => null];

        return [
            '日' => [$day, '今日の AI の利用の上限に近いため、音声は取り込めません。'],
            '月' => [[...$day, 'daily_usd' => null, 'monthly_usd' => 0.000001], '今月の AI の利用の上限に近いため、音声は取り込めません。'],
            '累計' => [[...$day, 'daily_usd' => null, 'total_usd' => 0.000001], 'AI の利用の上限に近いため、音声は取り込めません。'],
        ];
    }

    /**
     * 議事録の区分が次の依頼を受けられなければ、文字起こしを依頼する前に断る(文は超えた期間で言い換える)。
     *
     * @param  array<string, mixed>  $budget
     */
    #[DataProvider('minutesBudgets')]
    public function test_the_minutes_budget_is_checked_before_the_transcription(array $budget, string $message): void
    {
        config(['ai_runs.budget.default' => $budget]);

        $this->assertRolledBack(['file' => $this->audioFile('定例.mp3')], 'ai', $message);
    }

    public function test_the_transcription_budget_adds_that_text_import_still_works(): void
    {
        config(['ai_runs.budget.transcription.daily_usd' => 0.000001]);

        $this->assertRolledBack(
            ['file' => $this->audioFile('定例.mp3')],
            'ai',
            '今日の音声の取り込みの上限に達しました。テキストの取り込みは使用できます。',
        );
    }

    public function test_an_unreadable_audio_adds_the_supported_formats(): void
    {
        $file = UploadedFile::fake()->createWithContent('定例.mp3', $this->sampleContent('mp3'));

        $message = '音声を読み取れませんでした。対応する形式は mp3、m4a(AAC・ALAC)、wav(PCM・A-law・μ-law・ADPCM)です。'
            .'別の形式の場合は mp3 に変換してから上げ直してください。';

        $this->assertRolledBack(['file' => $file], 'ai', $message);
    }

    public function test_an_audio_over_the_maximum_length_is_declined_without_a_guide(): void
    {
        $kinds = (array) config('ai_runs.kinds');
        $kinds['meeting.transcription'] = [...(array) $kinds['meeting.transcription'], 'max_audio_seconds' => 1];
        config(['ai_runs.kinds' => $kinds]);

        $this->assertRolledBack(['file' => $this->audioFile('定例.mp3')], 'ai', '音声が長すぎます。0 時間までの音声を取り込んでください');
    }

    /**
     * PHP の上限で落ちた送信は、音声なら添付の土台の大きさの文、テキストならテキストの大きさの文。
     *
     * @return array<string, array{string, string}>
     */
    public static function overPhpLimits(): array
    {
        return [
            '音声' => ['定例.M4A', 'ファイルの大きさは 95MB までです'],
            'テキスト' => ['定例.txt', TextImportReader::FILE_TOO_LARGE],
        ];
    }

    #[DataProvider('overPhpLimits')]
    public function test_a_file_over_the_php_limit_gets_the_size_message_of_its_kind(
        string $name,
        string $message,
    ): void {
        $path = tempnam(sys_get_temp_dir(), 'meeting');
        $this->assertIsString($path);
        $file = new UploadedFile($path, $name, null, UPLOAD_ERR_INI_SIZE, true);

        try {
            $this->assertRolledBack(['file' => $file], 'file', $message);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_meeting_module_without_attachments_rejects_the_audio(): void
    {
        RecordModule::query()->where('code', 'meeting')->update(['is_attachment_enabled' => false]);
        $counts = $this->counts();

        $this->postMeeting(['file' => $this->audioFile('定例.mp3')])
            ->assertStatus(302)->assertSessionHasErrors(['attachments']);

        $this->assertSame($counts, $this->counts());
    }

    public function test_a_guest_cannot_create_from_an_audio(): void
    {
        $counts = $this->counts();

        $this->postJson('/meetings', ['file' => $this->audioFile('定例.mp3')])
            ->assertForbidden()->assertJsonPath('message', RunRequestMessages::GUEST);

        $this->assertSame($counts, $this->counts());
    }

    /**
     * @return array<string, array{RecordModuleAction}>
     */
    public static function requiredActions(): array
    {
        return [
            'create' => [RecordModuleAction::Create],
            'read' => [RecordModuleAction::Read],
            'update' => [RecordModuleAction::Update],
        ];
    }

    #[DataProvider('requiredActions')]
    public function test_a_user_without_one_of_the_required_actions_cannot_create_from_an_audio(
        RecordModuleAction $action,
    ): void {
        $this->denyMeeting($action);
        $counts = $this->counts();

        $this->postMeeting(['file' => $this->audioFile('定例.mp3')])->assertForbidden();

        $this->assertSame($counts, $this->counts());
    }

    /**
     * 送った作成が 422 で、会議・添付・実体・対応・実行が残らず、実体のファイルも残らない。
     *
     * @param  array<string, mixed>  $input
     */
    private function assertRolledBack(array $input, string $key, string $message): void
    {
        $counts = $this->counts();

        $this->postMeeting($input)->assertStatus(302)->assertSessionHasErrors([$key => $message]);

        $this->assertSame($counts, $this->counts());
        $this->assertSame(0, $this->storedFileCount());
    }
}

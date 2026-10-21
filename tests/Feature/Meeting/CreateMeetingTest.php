<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Meeting\MeetingMinutesKind;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Enums\Meeting\TranscriptSource;
use App\Enums\Records\RecordModuleAction;
use App\Http\Controllers\Web\Meeting\MeetingController;
use App\Models\Ai\AiRun;
use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Queries\Records\Api\RecordFormQuery;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * テキストからの会議の作成(docs の 設計/業務ツール/会議/処理.md の 3.2、行に分ける単位と文字コード = README.md の 2 節、材料 = 文字起こしと議事録.md の 5.3)。
 * 入口から送り、会議・書き起こし・発言の行・会議と実行の対応・実行と送る文を確かめる。
 *
 * 発言の行の形 Segment = 話者・開始の秒・終了の秒・発言。
 *
 * @phpstan-type Segment array{string|null, string|null, string|null, string}
 */
final class CreateMeetingTest extends TransactionalTestCase
{
    use UsesMeetings;

    private const VTT = "WEBVTT\n\n00:00:01.000 --> 00:00:04.500\n<v 佐藤>はじめます</v>\n\n"
        ."01:02:03.250 --> 01:02:05.000\n<v.loud 鈴木>了解です";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    /**
     * @return array<string, array{string|null, string, TranscriptSource, string|null, list<Segment>, non-empty-string}>
     */
    public static function sources(): array
    {
        $plain = [[null, null, null, '最初の発言'], [null, null, null, '次の発言']];
        $plainText = "書き起こし:\n最初の発言\n次の発言";

        return [
            '.txt' => ['定例.txt', "最初の発言\n\n  次の発言  \n", TranscriptSource::TextFile, '定例.txt', $plain, $plainText],
            '.md' => ['定例.MD', "最初の発言\r\n\r\n次の発言", TranscriptSource::TextFile, '定例.MD', $plain, $plainText],
            '.vtt' => ['定例.vtt', self::VTT, TranscriptSource::TextFile, '定例.vtt', [
                ['佐藤', '1.000', '4.500', 'はじめます'],
                ['鈴木', '3723.250', '3725.000', '了解です'],
            ], "書き起こし:\n[00:00:01] 話者 佐藤: はじめます\n[01:02:03] 話者 鈴木: 了解です"],
            '貼り付け' => [null, "最初の発言\n次の発言", TranscriptSource::Paste, null, $plain, $plainText],
        ];
    }

    /**
     * @param  list<Segment>  $segments
     * @param  non-empty-string  $transcriptText
     */
    #[DataProvider('sources')]
    public function test_creates_a_meeting_its_transcript_and_the_minutes_request_from_each_source(
        ?string $fileName,
        string $content,
        TranscriptSource $source,
        ?string $storedName,
        array $segments,
        string $transcriptText,
    ): void {
        $user = $this->user();
        $payload = $fileName === null
            ? ['text' => $content]
            : ['file' => UploadedFile::fake()->createWithContent($fileName, $content)];

        $this->actingAs($user)->post('/meetings', $payload)
            ->assertStatus(303)->assertRedirect('/records/data/meeting')->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => MeetingController::CREATED]);

        $recordId = $this->latestMeetingId();
        $transcript = Transcript::query()->where('record_id', $recordId)->sole();
        $run = $this->lastRunOf($recordId);
        $aiRun = AiRun::query()->findOrFail($run->ai_run_id);
        $this->assertSame($storedName ?? '貼り付けたテキスト', $this->valuesOf($recordId)['title']);
        $this->assertSame(
            [$source, $storedName, 'ja'],
            [$transcript->source, $transcript->file_name, $transcript->language],
        );
        $this->assertSame($segments, $this->segmentsOf($transcript->id));
        $this->assertSame(
            [MeetingRunStage::Minutes, MeetingRunOrigin::Create, true],
            [$run->stage, $run->origin, $run->writes_title],
        );
        $this->assertSame(
            [MeetingMinutesKind::CODE, $recordId, [$recordId], $user->id],
            [$aiRun->kind, $aiRun->record_id, $aiRun->source_record_ids, $aiRun->requester_user_id],
        );
        $this->assertStringStartsWith("会議の情報:\n件名: (未入力)\n", $this->userTextOf($aiRun->id));
        $this->assertStringEndsWith($transcriptText, $this->userTextOf($aiRun->id));
    }

    /**
     * 人が入れた件名は仮の件名にせず、材料に入れる。閲覧者の表示名・閲覧組織の名前・アジェンダ・議事メモを渡し、プロジェクトは渡さない。
     */
    public function test_a_title_and_the_fields_given_by_a_person_are_in_the_prompt_and_the_ai_keeps_the_title(): void
    {
        $user = $this->user();
        $viewers = [$this->namedUser('佐藤 花子'), $this->namedUser('鈴木 一郎')];
        $department = $this->namedDepartment('開発部');
        $values = [
            'title' => '週次の定例',
            'held_at' => '2026-10-21T10:30+09:00',
            'viewer_users' => [$viewers[0]->id, $viewers[1]->id],
            'viewer_departments' => [$department->id],
            'agenda' => '進み具合',
            'notes' => '遅れなし',
            'summary' => '入力に無い欄は渡さない',
        ];

        $this->actingAs($user)->post('/meetings', ['text' => '[10:00] 佐藤: はじめます', 'values' => $values])
            ->assertSessionHasNoErrors();

        $recordId = $this->latestMeetingId();
        $this->assertFalse($this->lastRunOf($recordId)->writes_title);
        $this->assertSame('週次の定例', $this->valuesOf($recordId)['title']);
        $this->assertArrayNotHasKey('summary', $this->valuesOf($recordId));
        $this->assertSame(implode("\n", [
            '会議の情報:',
            '件名: 週次の定例',
            '日時: 2026-10-21 10:30',
            '閲覧者: 佐藤 花子、鈴木 一郎',
            '閲覧組織: 開発部',
            '',
            'アジェンダ:',
            '進み具合',
            '',
            '議事メモ:',
            '遅れなし',
            '',
            '書き起こし:',
            '[10:00] 佐藤: はじめます',
        ]), $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    public function test_the_values_without_viewers_and_notes_are_none(): void
    {
        $recordId = $this->pastedMeeting($this->user(), '発言', ['held_at' => '2026-10-21T00:00Z']);

        $this->assertStringStartsWith(implode("\n", [
            '会議の情報:',
            '件名: (未入力)',
            '日時: 2026-10-21 09:00',
            '閲覧者: なし',
            '閲覧組織: なし',
            '',
            'アジェンダ:',
            'なし',
            '',
            '議事メモ:',
            'なし',
        ]), $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedRequests(): array
    {
        return [
            '上限を超える' => ['budget'],
            '長すぎる' => ['input'],
        ];
    }

    /**
     * 依頼の 422 で、会議・書き起こし・発言の行・対応が残らない。テキストのファイルは添付に残さない。
     */
    #[DataProvider('rejectedRequests')]
    public function test_a_rejected_request_rolls_everything_back(string $limit): void
    {
        if ($limit === 'budget') {
            config(['ai_runs.budget.default.daily_usd' => 0.000001]);
        } else {
            $this->setMaxInputChars(10);
        }

        $counts = $this->counts();

        $this->postMeeting(['file' => ['a.txt', '発言']])->assertStatus(302)->assertSessionHasErrors(['ai']);

        $this->assertSame($counts, $this->counts());
    }

    public function test_a_successful_text_file_is_not_attached(): void
    {
        $counts = [RecordAttachment::query()->count(), Upload::query()->count()];

        $this->postMeeting(['file' => ['a.txt', '発言']])->assertSessionHasNoErrors();

        $this->assertSame($counts, [RecordAttachment::query()->count(), Upload::query()->count()]);
        $this->assertSame(1, Transcript::query()->where('record_id', $this->latestMeetingId())->count());
    }

    public function test_a_guest_cannot_create(): void
    {
        $counts = $this->counts();

        $this->post('/meetings', ['text' => '発言'])->assertForbidden();

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
    public function test_a_user_without_one_of_the_required_actions_cannot_create(RecordModuleAction $action): void
    {
        $this->denyMeeting($action);
        $counts = $this->counts();

        $this->postMeeting(['text' => '発言'])->assertForbidden();

        $this->assertSame($counts, $this->counts());
    }

    /**
     * 403 の文は、ゲスト(AI に依頼できない)と、業務モジュールの操作を許されない人で分ける(判定は一覧の操作を出す条件と同じ 1 か所)。
     */
    public function test_the_forbidden_message_tells_a_guest_from_a_user_without_the_actions(): void
    {
        $this->postJson('/meetings', ['text' => '発言'])
            ->assertForbidden()->assertJsonPath('message', RequestPrompt::GUEST);

        $this->denyMeeting(RecordModuleAction::Update);

        $this->actingAs($this->user())->postJson('/meetings', ['text' => '発言'])
            ->assertForbidden()->assertJsonPath('message', RecordFormQuery::NOT_ALLOWED_MESSAGE);
    }

    /** 接続元ごとの回数の上限は持たない(docs の 設計/AI/実行と費用.md 5.2)。費用の上限の内なら、同じ接続元から何回でも受け付ける */
    public function test_accepts_more_requests_from_one_address_than_the_former_daily_count(): void
    {
        $runs = AiRun::query()->count();

        for ($attempt = 1; $attempt <= 11; $attempt++) {
            $this->postMeeting(['text' => "発言 {$attempt}"])->assertStatus(303)->assertSessionHasNoErrors();
        }

        $this->assertSame($runs + 11, AiRun::query()->count());
    }

    private function setMaxInputChars(int $max): void
    {
        $kinds = (array) config('ai_runs.kinds');
        $kinds[MeetingMinutesKind::CODE] = [...(array) $kinds[MeetingMinutesKind::CODE], 'max_input_chars' => $max];
        config(['ai_runs.kinds' => $kinds]);
    }

    /**
     * @return list<Segment>
     */
    private function segmentsOf(int $transcriptId): array
    {
        return array_values(TranscriptSegment::query()->where('transcript_id', $transcriptId)->orderBy('seq')->get()
            ->map(fn (TranscriptSegment $segment) => [
                $segment->speaker_label, $segment->started_at_sec, $segment->ended_at_sec, $segment->text,
            ])
            ->all());
    }
}

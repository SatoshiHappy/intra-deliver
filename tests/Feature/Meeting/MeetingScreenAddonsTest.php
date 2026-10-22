<?php

namespace Tests\Feature\Meeting;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Queries\Meeting\MeetingLimits;
use App\Queries\Meeting\MeetingRegenerableQuery;
use App\Queries\Meeting\MeetingScreenAddons;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の一覧と詳細に足す操作・札・注(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.1・9.3、押せる人・押せる会議・押せる時 =
 * 処理.md の 3.2・3.7・3.8)。入れ物の一覧・詳細の応答と、口の実装を直に呼んだ結果で確かめる。
 */
final class MeetingScreenAddonsTest extends TransactionalTestCase
{
    use UsesMeetings;

    private const PROCESSING = ['label' => '処理中', 'tone' => 'pending'];

    private const FAILED = ['label' => '失敗', 'tone' => 'rejected'];

    private const NOTES = ['summary' => '議事録の 5 欄は、再作成で上書きされます。前の値は更新履歴で確認できます。'];

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_the_list_shows_create_from_audio_and_the_badges_of_the_rows(): void
    {
        $processing = $this->pastedMeeting($this->creator);
        $failed = $this->manualMeeting();
        MeetingRun::factory()->declined()->create(['record_id' => $failed]);
        $this->manualMeeting();

        $this->actingAs($this->creator)->get('/records/data/meeting')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Records/Index')
                ->where('addons.actions', [self::create()])
                ->where('addonBadges', [$processing => [self::PROCESSING], $failed => [self::FAILED]])
            );
    }

    /**
     * @return array<string, array{RecordModuleAction}>
     */
    public static function createActions(): array
    {
        return [
            '登録' => [RecordModuleAction::Create],
            '参照' => [RecordModuleAction::Read],
            '更新' => [RecordModuleAction::Update],
        ];
    }

    #[DataProvider('createActions')]
    public function test_create_from_audio_is_not_shown_without_one_of_the_actions(RecordModuleAction $denied): void
    {
        $this->denyMeeting($denied);

        $this->assertSame(['actions' => []], $this->addons()->index($this->contextOf($this->creator)));
    }

    public function test_create_from_audio_is_not_shown_to_a_guest(): void
    {
        $this->assertSame(['actions' => []], $this->addons()->index($this->contextOf(null)));
        $this->get('/records/data/meeting')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('addons.actions', []));
    }

    public function test_the_badges_follow_the_state_and_carry_only_the_meetings_with_a_state(): void
    {
        $processing = $this->pastedMeeting($this->creator);
        $succeeded = $this->completedMeeting();
        $failed = $this->failedMeeting();
        $manual = $this->manualMeeting();

        $recordIds = [$processing, $succeeded, $failed, $manual];

        $badges = $this->addons()->badges($this->contextOf($this->creator), $recordIds);

        $this->assertSame([$processing => [self::PROCESSING], $failed => [self::FAILED]], $badges);
    }

    public function test_a_processing_meeting_shows_regenerate_disabled_with_the_badge_and_the_note(): void
    {
        $recordId = $this->pastedMeeting($this->creator);

        $this->actingAs($this->creator)->get("/records/data/meeting/{$recordId}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Records/Show')
                ->where('addons', [
                    'actions' => [self::regenerate(true, MeetingScreenAddons::PROCESSING_REASON)],
                    'badges' => [self::PROCESSING],
                    'fieldNotes' => self::NOTES,
                ])
            );
    }

    public function test_a_completed_meeting_can_be_regenerated_and_a_failed_one_carries_the_failed_badge(): void
    {
        $completed = $this->completedMeeting();
        $failed = $this->failedMeeting();
        $context = $this->contextOf($this->creator);

        $this->assertSame(
            ['actions' => [self::regenerate(true, null)], 'badges' => [], 'fieldNotes' => self::NOTES],
            $this->addons()->show($context, $completed),
        );
        $this->assertSame(
            ['actions' => [self::regenerate(true, null)], 'badges' => [self::FAILED], 'fieldNotes' => self::NOTES],
            $this->addons()->show($context, $failed),
        );
    }

    public function test_a_meeting_with_an_audio_but_no_transcript_passes_has_transcript_false(): void
    {
        $recordId = $this->audioMeeting($this->creator);
        $this->assertSame(AiRunStatus::Failed, $this->completeTranscription($this->lastRunIdOf($recordId), [])->status);

        $this->assertSame(
            ['actions' => [self::regenerate(false, null)], 'badges' => [self::FAILED], 'fieldNotes' => self::NOTES],
            $this->addons()->show($this->contextOf($this->creator), $recordId),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function withoutAcceptedAudio(): array
    {
        return [
            '添付が無い' => ['removed'],
            '受けない形式だけ' => ['video'],
            '期限で消えた音声だけ' => ['purged'],
        ];
    }

    #[DataProvider('withoutAcceptedAudio')]
    public function test_without_a_transcript_or_an_accepted_audio_regenerate_is_disabled_with_the_entry_reason(
        string $case,
    ): void {
        $recordId = $this->audioMeeting($this->creator);
        $this->assertSame(AiRunStatus::Failed, $this->completeTranscription($this->lastRunIdOf($recordId), [])->status);
        $attachments = RecordAttachment::query()->where('record_id', $recordId);

        if ($case === 'purged') {
            Upload::query()->whereIn('id', $attachments->pluck('upload_id'))
                ->update(['purged_at' => now(), 'purge_due_on' => '2026-01-01']);
        } else {
            $attachments->delete();
        }

        if ($case === 'video') {
            $input = new RecordInput(attachFiles: [
                UploadedFile::fake()->createWithContent('録画.mp4', $this->sampleContent('mp4')),
            ]);
            app(UpdateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $recordId, $input, null);
        }

        $action = self::regenerate(false, MeetingRegenerableQuery::NO_MATERIAL);

        $this->assertSame(
            ['actions' => [$action], 'badges' => [self::FAILED], 'fieldNotes' => self::NOTES],
            $this->addons()->show($this->contextOf($this->creator), $recordId),
        );
    }

    public function test_processing_is_the_reason_before_the_missing_transcript_and_audio(): void
    {
        $recordId = $this->manualMeeting();
        MeetingRun::factory()->create(['record_id' => $recordId]);

        $action = self::regenerate(false, MeetingScreenAddons::PROCESSING_REASON);

        $this->assertSame(
            ['actions' => [$action], 'badges' => [self::PROCESSING], 'fieldNotes' => self::NOTES],
            $this->addons()->show($this->contextOf($this->creator), $recordId),
        );
    }

    public function test_a_manual_meeting_has_no_regenerate_no_badge_and_no_note(): void
    {
        $recordId = $this->manualMeeting();

        $this->actingAs($this->creator)->get("/records/data/meeting/{$recordId}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('addons', ['actions' => [], 'badges' => [], 'fieldNotes' => []])
            );
    }

    /**
     * @return array<string, array{RecordModuleAction|null}>
     */
    public static function regenerateDenials(): array
    {
        return ['ゲスト' => [null], '参照' => [RecordModuleAction::Read], '更新' => [RecordModuleAction::Update]];
    }

    /**
     * 再作成を出さない見る人にも、注は出す(見る人の権限によらない)。
     */
    #[DataProvider('regenerateDenials')]
    public function test_regenerate_is_not_shown_to_a_guest_or_without_read_or_update_but_the_note_is(
        ?RecordModuleAction $denied,
    ): void {
        $recordId = $this->pastedMeeting($this->creator);

        if ($denied !== null) {
            $this->denyMeeting($denied);
        }

        $context = $this->contextOf($denied === null ? null : $this->creator);

        $this->assertSame(
            ['actions' => [], 'badges' => [self::PROCESSING], 'fieldNotes' => self::NOTES],
            $this->addons()->show($context, $recordId),
        );
    }

    public function test_regenerate_is_shown_without_create(): void
    {
        $recordId = $this->pastedMeeting($this->creator);
        $this->denyMeeting(RecordModuleAction::Create);

        $show = $this->addons()->show($this->contextOf($this->creator), $recordId);

        $this->assertSame([self::regenerate(true, MeetingScreenAddons::PROCESSING_REASON)], $show['actions']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function create(?string $audioBlockedMessage = null): array
    {
        return [
            'code' => 'create', 'label' => '音声から作成', 'dialog' => 'meeting.create',
            'props' => [
                'audioMaxBytes' => 99_614_720,
                'audioBlockedMessage' => $audioBlockedMessage,
            ],
            'disabledReason' => null, 'changesValues' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function regenerate(
        bool $hasTranscript,
        ?string $disabledReason,
        ?string $audioBlockedMessage = null,
    ): array {
        return [
            'code' => 'regenerate', 'label' => '再作成', 'dialog' => 'meeting.regenerate',
            'props' => [
                'hasTranscript' => $hasTranscript,
                'instructionsMaxLength' => MeetingLimits::INSTRUCTIONS_MAX_LENGTH,
                'audioBlockedMessage' => $audioBlockedMessage,
            ],
            'disabledReason' => $disabledReason,
            'changesValues' => true,
        ];
    }

    private function addons(): MeetingScreenAddons
    {
        return app(MeetingScreenAddons::class);
    }

    /** 入れ物の新規登録で作った会議(書き起こしも対応も無い) */
    private function manualMeeting(): int
    {
        $input = new RecordInput(['title' => '手で作った会議']);

        return app(CreateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $input);
    }

    /** 議事録の実行が成功した会議 */
    private function completedMeeting(): int
    {
        $recordId = $this->pastedMeeting($this->creator);
        $this->assertSame(AiRunStatus::Succeeded, $this->completeMinutes($this->lastRunIdOf($recordId))->status);

        return $recordId;
    }

    /** 議事録の実行が失敗した会議(出力の要約が空) */
    private function failedMeeting(): int
    {
        $recordId = $this->pastedMeeting($this->creator);
        $run = $this->completeMinutes($this->lastRunIdOf($recordId), [...self::OUTPUT, 'summary' => '']);
        $this->assertSame(AiRunStatus::Failed, $run->status);

        return $recordId;
    }
}

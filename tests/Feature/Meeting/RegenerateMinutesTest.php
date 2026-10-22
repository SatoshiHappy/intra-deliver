<?php

namespace Tests\Feature\Meeting;

use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\PromptAgent;
use App\Actions\Meeting\RegenerateMinutes;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Enums\Records\RecordModuleAction;
use App\Http\Controllers\Web\Meeting\MeetingController;
use App\Models\Master\User;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 書き起こしからの議事録の再作成(docs の 設計/業務ツール/会議/処理.md の 3.7、材料 = 文字起こしと議事録.md の 5.3・5.4)。
 */
final class RegenerateMinutesTest extends TransactionalTestCase
{
    use RegistersRecordHolds;
    use UsesMeetings;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_requests_the_minutes_from_the_transcript_with_the_instructions(): void
    {
        $recordId = $this->completedMeeting();

        $this->regenerate($recordId, ['instructions' => '短くまとめてください'])
            ->assertStatus(303)->assertRedirect("/records/data/meeting/{$recordId}")->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast', [
                'type' => 'success', 'message' => MeetingController::REGENERATION_REQUESTED,
            ]);

        $run = $this->lastRunOf($recordId);
        $this->assertSame(
            [MeetingRunStage::Minutes, MeetingRunOrigin::Regenerate, false],
            [$run->stage, $run->origin, $run->writes_title],
        );
        $expected = "書き起こし:\n最初の発言\n次の発言\n\n追加の指示:\n短くまとめてください";
        $this->assertStringEndsWith($expected, $this->userTextOf((int) $run->ai_run_id));
    }

    public function test_without_instructions_the_prompt_has_no_instructions_section(): void
    {
        $recordId = $this->completedMeeting();

        $this->regenerate($recordId, [])->assertSessionHasNoErrors();

        $this->assertStringEndsWith("書き起こし:\n最初の発言\n次の発言", $this->userTextOf($this->lastRunIdOf($recordId)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedInstructions(): array
    {
        return [
            '501 字' => [str_repeat('あ', 501), RegenerateMinutes::INSTRUCTIONS_TOO_LONG],
            'U+0000' => ["短\0く", RegenerateMinutes::INSTRUCTIONS_INVALID_CHARACTER],
        ];
    }

    #[DataProvider('rejectedInstructions')]
    public function test_rejects_long_instructions_or_an_invalid_character(string $instructions, string $error): void
    {
        $recordId = $this->completedMeeting();
        $count = MeetingRun::query()->count();

        $this->regenerate($recordId, ['instructions' => $instructions])
            ->assertSessionHasErrors(['instructions' => $error]);

        $this->assertSame($count, MeetingRun::query()->count());
    }

    public function test_accepts_instructions_of_500_characters(): void
    {
        $this->regenerate($this->completedMeeting(), ['instructions' => str_repeat('あ', 500)])
            ->assertSessionHasNoErrors();
    }

    public function test_a_meeting_in_processing_cannot_be_regenerated(): void
    {
        $recordId = $this->pastedMeeting($this->creator);

        $this->regenerate($recordId, [])->assertSessionHasErrors(['meeting' => RegenerateMinutes::PROCESSING]);
    }

    public function test_a_held_meeting_cannot_be_regenerated(): void
    {
        $recordId = $this->completedMeeting();
        $this->registerRecordHolds([$recordId => 'workflow-case:1']);

        $this->regenerate($recordId, [])->assertSessionHasErrors(['meeting' => RegenerateMinutes::HELD]);
    }

    public function test_a_manual_meeting_cannot_be_regenerated(): void
    {
        $input = new RecordInput(['title' => '手で作った']);
        $recordId = app(CreateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $input);

        $this->regenerate($recordId, [])->assertSessionHasErrors(['meeting' => RegenerateMinutes::NOT_FROM_AUDIO]);
    }

    public function test_a_missing_meeting_an_unrelated_user_and_a_record_of_another_module_are_not_found(): void
    {
        $recordId = $this->completedMeeting();
        $otherModuleId = RecordModule::query()->where('code', '!=', 'meeting')->whereNull('parent_record_module_id')
            ->orderBy('id')->firstOrFail()->id;
        $otherRecordId = (int) Record::query()->where('record_module_id', $otherModuleId)->value('id');

        $this->regenerate($recordId + 1000, [])->assertNotFound();
        $this->regenerate($recordId, [], $this->user())->assertNotFound();
        $this->regenerate($otherRecordId, [])->assertNotFound();
    }

    public function test_a_guest_cannot_regenerate(): void
    {
        $recordId = $this->completedMeeting();

        $this->post("/meetings/{$recordId}/regenerate")->assertForbidden();
    }

    /**
     * @return array<string, array{RecordModuleAction}>
     */
    public static function requiredActions(): array
    {
        return ['read' => [RecordModuleAction::Read], 'update' => [RecordModuleAction::Update]];
    }

    /**
     * 403 は会議の有無より先に返す(存在を漏らさない)。
     */
    #[DataProvider('requiredActions')]
    public function test_a_user_without_read_or_update_is_forbidden_before_the_lookup(RecordModuleAction $action): void
    {
        $recordId = $this->completedMeeting();
        $this->denyMeeting($action);

        $this->regenerate($recordId, [])->assertForbidden();
        $this->regenerate($recordId + 1000, [])->assertForbidden();
    }

    /**
     * 送る文の件名(4 つの経路): 作成が成功した = AI が書いた件名、作成が失敗したまま = 未入力、失敗の後に人が件名を直した = 直した件名、
     * 失敗の後に再作成が成功した後の 2 回目 = 未入力(件名は仮の値のまま)。
     */
    public function test_the_title_in_the_prompt_follows_whether_it_is_still_provisional(): void
    {
        $succeeded = $this->completedMeeting();
        $failed = $this->failedMeeting();
        $edited = $this->failedMeeting();
        $input = new RecordInput(['title' => '直した件名']);
        app(UpdateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $edited, $input, null);
        $regenerated = $this->failedMeeting();
        $this->regenerate($regenerated, [])->assertSessionHasNoErrors();
        $this->completeMinutes($this->lastRunIdOf($regenerated));

        foreach ([$succeeded, $failed, $edited, $regenerated] as $recordId) {
            $this->regenerate($recordId, [])->assertSessionHasNoErrors();
        }

        $this->assertSame(
            ['件名: AI の件名', '件名: (未入力)', '件名: 直した件名', '件名: (未入力)'],
            array_map(
                fn (int $recordId) => explode("\n", $this->userTextOf($this->lastRunIdOf($recordId)))[1],
                [$succeeded, $failed, $edited, $regenerated],
            ),
        );
    }

    /** 貼り付けから作り、議事録が書けた会議 */
    private function completedMeeting(): int
    {
        $recordId = $this->pastedMeeting($this->creator);
        $this->completeMinutes($this->lastRunIdOf($recordId));

        return $recordId;
    }

    /** ファイルから作り、議事録の実行が失敗した会議(件名は仮のファイル名のまま) */
    private function failedMeeting(): int
    {
        $this->postMeeting(['file' => ['定例.txt', '発言']], $this->creator)->assertSessionHasNoErrors();
        $recordId = $this->latestMeetingId();
        PromptAgent::fake([[...self::OUTPUT, 'summary' => '']]);
        rescue(fn () => app(ExecutePrompt::class)->execute($this->lastRunIdOf($recordId)), report: false);

        return $recordId;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return TestResponse<Response>
     */
    private function regenerate(int $recordId, array $input, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->creator)->from("/records/data/meeting/{$recordId}")
            ->post("/meetings/{$recordId}/regenerate", $input);
    }
}

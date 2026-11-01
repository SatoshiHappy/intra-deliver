<?php

namespace Tests\Feature\Meeting;

use App\Actions\Meeting\MeetingAudioRequest;
use App\Enums\Builtin\Meeting;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Meeting\MeetingFieldsQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の操作の前の閲覧の判定は、会議の業務モジュールの read と行の判定だけで決め、申請の経路を含めない(docs の
 * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節の「使わない所」= 会議の操作は会議のレコードの詳細の画面から行う)。
 * 会議の業務モジュールをコンテンツにした未完了の申請の申請者は、申請の経路では会議を見られるが、会議の作成者でも関係者でもないので
 * 行の判定を通らない。その人の再作成・文字起こしの依頼・議事録の材料の読み取りは、申請の経路が無い人と同じく 404。
 */
final class MeetingApplicationRouteTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use UsesMeetings;

    private User $applicant;

    private int $recordId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->recordId = $this->pastedMeeting($this->user());
        $this->completeMinutes($this->lastRunIdOf($this->recordId));
        $this->applicant = $this->user();
        $this->applyFor($this->applicant, $this->recordId);
    }

    public function test_the_applicant_sees_the_meeting_only_through_the_application(): void
    {
        $access = app(RecordViewAccess::class);
        $requester = new Requester($this->applicant->id, null);
        $date = CarbonImmutable::today();

        $this->assertSame([true, false], [
            $access->canView($requester, Meeting::CODE, $this->recordId, $date),
            $access->canViewInModule($requester, Meeting::CODE, $this->recordId, $date),
        ]);
    }

    public function test_regenerating_through_the_application_only_is_not_found(): void
    {
        $counts = $this->counts();

        $this->actingAs($this->applicant)->postJson("/meetings/{$this->recordId}/regenerate", [])->assertNotFound();

        $this->assertSame($counts, $this->counts());
    }

    /**
     * 閲覧の判定は音声の確かめより先なので、音声の id は無い値でよい(判定を通ってしまえば 404 でなく LogicException になる)。
     */
    public function test_requesting_a_transcription_through_the_application_only_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        app(MeetingAudioRequest::class)
            ->execute($this->contextOf($this->applicant), $this->recordId, 0, MeetingRunOrigin::Regenerate, false);
    }

    public function test_reading_the_fields_for_the_minutes_through_the_application_only_is_not_found(): void
    {
        $requester = new Requester($this->applicant->id, null);

        $this->expectException(ModelNotFoundException::class);
        app(MeetingFieldsQuery::class)->read($requester, $this->recordId, CarbonImmutable::today(), 'ja');
    }

    /**
     * 会議の業務モジュールをコンテンツにしたフローの、会議を申請書のレコードにした未完了の申請(申請者が申請のノードを処理済み)。
     */
    private function applyFor(User $applicant, int $recordId): void
    {
        $content = WorkflowContent::factory()->create(['record_module_id' => $this->meetingModule()->id]);
        $flow = WorkflowFlow::factory()->create(['workflow_content_id' => $content->id]);
        $case = WorkflowCase::factory()->active()
            ->createOne(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id, 'record_id' => $recordId]);
        [$apply] = $this->caseNodes($case, 2);
        $this->process($apply, $applicant, WorkflowAction::Apply);
    }
}

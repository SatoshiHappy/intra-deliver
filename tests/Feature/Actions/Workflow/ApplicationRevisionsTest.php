<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplicationRecords;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\PullBackCase;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Workflow\CaseActor;
use Illuminate\Support\Facades\DB;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 申請・再申請の履歴の行が更新履歴の版を指す(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節・9 節の「W-2」):
 * 申請・再申請はいつもその操作の版(値を変えない再申請でも)を指し、版のレコードは案件のレコード。承認などの処理の行は版を指さない。
 * 契機の実行者は代理のときだけ入る。版の契機は写しなので、申請を消しても変わらない。フロー = 申請 → 承認 1(u0002)→ 承認 2(u0003)。
 */
final class ApplicationRevisionsTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_application_rows_point_at_the_revision_of_each_operation_and_other_rows_at_none(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);
        $this->operate(PullBackCase::class, $this->seededUser('u0002'), $case);
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');

        $rowId = Record::query()->where('parent_record_id', $case->record_id)->soleValue('id');
        $row = new RecordDetailRowInput($rowId, ['name' => '要件の整理', 'due_date' => '2026-11-30']);
        $unchanged = new RecordInput($this->projectInput()->record->values, ['milestones' => [$row]]);
        $this->reapply($this->seededUser('u0001'), $case, new ApplicationInput(null, null, $unchanged));

        $histories = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->get();
        $revisionRows = RecordRevision::query()->where('record_id', $case->record_id)->orderBy('revision')->get();
        $revisions = array_values($revisionRows->all());
        $this->assertSame(
            [[1, RecordRevisionAction::Create, '申請', '申請'], [2, RecordRevisionAction::Update, '再申請', '申請']],
            array_map(fn (RecordRevision $revision) => [
                $revision->revision, $revision->action, $revision->origin_operation, $revision->origin_node_name,
            ], $revisions),
        );
        $this->assertSame(
            [
                [WorkflowAction::Apply, $revisions[0]->id],
                [WorkflowAction::Approve, null],
                [WorkflowAction::PullBack, null],
                [WorkflowAction::SendBack, null],
                [WorkflowAction::Reapply, $revisions[1]->id],
            ],
            array_values($histories->map(
                fn (WorkflowCaseHistory $history) => [$history->action, $history->record_revision_id],
            )->all()),
        );
        [$applied, $reapplied] = [$revisions[0]->id, $revisions[1]->id];
        $guest = new Requester(null, null);
        $diff = app(RecordRevisionQuery::class)->diffBetween($guest, $applied, $reapplied, now(), 'ja');
        $this->assertSame([[], [], []], [$diff['fields'], $diff['details'], $diff['attachments']]);
    }

    public function test_the_origin_carries_the_executor_only_when_the_actor_acts_for_another(): void
    {
        $records = app(ApplicationRecords::class);
        $proxy = $records->origin(WorkflowAction::Reapply, '申請', new CaseActor(1, 2));
        $self = $records->origin(WorkflowAction::Apply, '申請', CaseActor::of(1));

        $this->assertSame(
            [['再申請', '申請', 2], ['申請', '申請', null]],
            array_map(
                fn (RecordRevisionOrigin $origin) => [$origin->operation, $origin->nodeName, $origin->executorUserId],
                [$proxy, $self],
            ),
        );
    }

    public function test_deleting_the_applications_keeps_the_origin_of_the_revisions(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $recordId = $case->record_id;

        DB::statement('TRUNCATE workflow_flows, workflow_cases CASCADE');

        $this->assertSame(0, WorkflowCase::query()->count());
        $revision = RecordRevision::query()->where('record_id', $recordId)->sole();
        $this->assertSame(['申請', '申請'], [$revision->origin_operation, $revision->origin_node_name]);
    }
}

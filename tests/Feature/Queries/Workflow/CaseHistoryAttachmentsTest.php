<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordRevisionDiff;
use App\Queries\Workflow\CaseDetailQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 処理履歴の行は値の差分を持たず、申請の間に足した添付の変化は更新履歴の版の差分に出る(docs の
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 3 節、更新履歴.md の 4.2 節)。
 */
final class CaseHistoryAttachmentsTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsWorkflowCases;

    public function test_an_attachment_added_between_the_submissions_is_in_the_revision_diff_not_in_the_history(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00'));
        [$applicant] = $this->seededUsers('u0001');
        $module = $this->recordModule('wf_files');
        $title = $this->field($module, 'title', attributes: ['is_title' => true]);
        $record = Record::factory()->create([
            'record_module_id' => $module->id,
            'field_values' => [$title->id => '件名'],
        ]);
        $case = WorkflowCase::factory()->active()->create([
            'workflow_flow_id' => $this->flowOf($module->id, $applicant)->id,
            'user_id' => $applicant->id,
            'record_id' => $record->id,
        ]);
        [$apply] = $this->caseNodes($case, 2);

        $applied = $this->process($apply, $applicant, WorkflowAction::Apply);
        $this->pointAtSubmittedRevision($applied, $record, [$title->id => '件名'], []);
        RecordAttachment::factory()->create([
            'record_module_id' => $module->id,
            'record_id' => $record->id,
            'name' => '追加.pdf',
        ]);
        $this->travel(1)->minutes();
        $reapplied = $this->process($apply, $applicant, WorkflowAction::Reapply);
        $this->pointAtSubmittedRevision($reapplied, $record, [$title->id => '件名'], []);

        $revisionDiff = app(RecordRevisionDiff::class)->between(
            new Requester($applicant->id, null),
            (int) $applied->refresh()->record_revision_id,
            (int) $reapplied->refresh()->record_revision_id,
            CarbonImmutable::parse('2026-10-13'),
            'ja',
        );
        $context = new AccessContext($applicant->id, null, null, CarbonImmutable::parse('2026-10-13'), 'ja');
        $histories = app(CaseDetailQuery::class)->show($context, $case->id)['histories'];

        $this->assertSame(['追加.pdf'], array_column($revisionDiff['attachments'], 'name'));
        $this->assertSame([], array_intersect_key($histories[1], ['diff' => 0, 'diffBaseAt' => 0]));
    }

    /**
     * 業務モジュールをコンテンツにしたフロー(申請(申請者)+ 承認)。
     */
    private function flowOf(int $moduleId, User $applicant): WorkflowFlow
    {
        $content = WorkflowContent::factory()->create(['record_module_id' => $moduleId]);
        $flow = WorkflowFlow::factory()->create(['workflow_content_id' => $content->id]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $flow->id]);
        $applicantGroup = $this->selection(SelectionConditionKind::User, $applicant->id);
        WorkflowRouteNode::factory()->count(2)
            ->sequence(
                ['position' => 1, 'authz_subject_group_id' => $applicantGroup],
                ['position' => 2],
            )
            ->create(['workflow_flow_version_id' => $version->id]);

        return $flow;
    }
}

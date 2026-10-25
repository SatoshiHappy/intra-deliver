<?php

namespace Tests\Support;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Workflow\RelatedCases;

/**
 * つながる申請と、業務レコードの詳細の節「このレコードを申請対象にする申請」のテストのデータ(docs の
 * 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節、設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.4 節)。
 * 申請書の業務モジュールと申請対象は {@see BuildsTargetFlows}、案件の行は {@see BuildsWorkflowCases} で作り、初期データの見本に頼らない。
 */
trait BuildsRelatedCases
{
    use BuildsTargetFlows;
    use BuildsWorkflowCases;

    /**
     * 業務モジュールのレコード(値なし)。
     */
    protected function recordIn(RecordModule|string $module): Record
    {
        $moduleId = $module instanceof RecordModule ? $module->id : $this->moduleNamed($module)->id;

        return Record::factory()->create(['record_module_id' => $moduleId]);
    }

    /**
     * 他モジュール参照の値 1 つ(業務モジュールの項目のコードで指す)。
     */
    protected function refer(Record $from, string $fieldCode, Record $to): void
    {
        $field = RecordField::query()->where('record_module_id', $from->record_module_id)->where('code', $fieldCode)
            ->sole();
        RecordReference::factory()->create([
            'record_module_id' => $from->record_module_id,
            'record_id' => $from->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            'referenced_record_module_id' => $to->record_module_id,
            'referenced_record_id' => $to->id,
        ]);
    }

    /**
     * 申請書のレコードを指し、申請のノードを申請者が処理済みにした案件(申請者は処理済の述語で見られる。未完了なら承認のノードが承認待ち)。申請日時を渡せばその値。
     */
    protected function appliedCase(
        WorkflowFlow $flow,
        Record $record,
        User $applicant,
        bool $completed = false,
        ?string $appliedAt = null,
    ): WorkflowCase {
        $factory = $completed ? WorkflowCase::factory()->completed() : WorkflowCase::factory()->active();
        $case = $factory->create([
            'workflow_flow_id' => $flow->id,
            'user_id' => $applicant->id,
            'record_id' => $record->id,
            ...($appliedAt === null ? [] : ['applied_at' => $appliedAt]),
        ]);
        [$applyNode, $approvalNode] = $this->caseNodes($case, 2);
        $this->process($applyNode, $applicant, WorkflowAction::Apply);

        if ($completed) {
            $this->process($approvalNode, $applicant, WorkflowAction::Approve);
        } else {
            $approvalNode->update(['state' => WorkflowNodeState::Waiting]);
        }

        return $case;
    }

    /**
     * 業務モジュールの read の許可を、ゲストと認証済みの全員から外す(閲覧できない業務モジュールを作る)。
     */
    protected function denyRead(string $moduleCode): void
    {
        $groupCode = RecordModuleAction::resourceGroupCode($moduleCode);
        $groupId = AuthzResourceGroup::query()->where('code', $groupCode)->sole()->id;
        $read = RecordModuleAction::Read->value;
        AuthzPolicy::query()->where('authz_resource_group_id', $groupId)->where('action', $read)->delete();
    }

    protected function moduleNamed(string $code): RecordModule
    {
        return RecordModule::query()->where('code', $code)->sole();
    }

    /**
     * 見る人が案件の詳細で見るつながる申請の群(申請の詳細が使う表示の口の結果を渡す)。
     *
     * @return list<array{heading: string, cases: list<array<string, mixed>>}>
     */
    protected function relatedGroupsOf(User $viewer, WorkflowCase $case): array
    {
        $context = $this->contextFor($viewer->id);
        $requester = Requester::fromAccessContext($context);
        $case = WorkflowCase::query()->with('workflowFlow')->findOrFail($case->id);
        $content = app(RecordDisplayQuery::class)->show($requester, (int) $case->record_id, $context->date, 'ja');

        return app(RelatedCases::class)->of($requester, $context->date, 'ja', $case, $content);
    }
}

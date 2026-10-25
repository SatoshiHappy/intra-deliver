<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use App\Support\PositiveIds;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 申請対象からの申請の画面(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.3 節): 新規の申請の画面に
 * 申請対象とその写しの値を足す。主体の判定をしない値の口は、既定の申請者の閲覧の判定の後に {@see ApplicationTargetValues} が呼ぶ。
 *
 * @phpstan-import-type ApplyForm from ApplicationFormQuery
 */
class ApplicationTargetForm
{
    public function __construct(
        private CaseEntryGate $gate,
        private RecordApplyFlows $recordApplyFlows,
        private RecordFormQuery $forms,
        private ApplicationFormQuery $applicationForms,
        private ApplicationTargetValues $applicationTargetValues,
    ) {}

    /**
     * @param  mixed  $applicantId  クエリ applicant(選ぶ代理元)
     * @param  mixed  $baseDate  クエリ base_date
     * @return ApplyForm
     *
     * @throws ModelNotFoundException<WorkflowFlow|Record> フローが無い・既定の申請者が申請対象を閲覧できないとき
     * @throws AuthorizationException 自分か代理元のどれもこの経路で申請できないとき(ゲストを含む)
     */
    public function fromApplicationTarget(
        AccessContext $context,
        string $flowCode,
        int $applicationTargetId,
        mixed $applicantId,
        mixed $baseDate = null,
    ): array {
        $this->gate->authorizeApplicationTargetApply($context, $flowCode);
        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->where('code', $flowCode)->firstOrFail();
        $applicationTarget = $this->applicationTargetValues->applicationTargetOf($flow)
            ?? throw new LogicException("申請対象からの申請をできるフロー {$flow->code} に申請対象の項目が無い");
        $applicant = $this->applicantOf($context, $flow->id, $applicantId);
        $requester = Requester::fromAccessContext($applicant);
        $moduleCode = $flow->workflowContent->recordModule->code
            ?? throw new LogicException("フロー {$flow->code} のコンテンツの業務モジュールを読み込んでいない");
        $module = $this->forms->definition($requester, $moduleCode, RecordModuleAction::Create, $context->date);
        $values = $this->applicationTargetValues->valuesFor(
            $requester, $applicationTarget, $module, $applicationTargetId, $context->date, $context->localeCode,
        ) ?? throw (new ModelNotFoundException)->setModel(Record::class, [$applicationTargetId]);
        $form = $this->applicationForms->newForm($context, $applicant, $flow, $module, $baseDate);
        $entry = ['module' => $applicationTarget['module'], 'record' => $applicationTargetId];

        return [...$form, 'targetValues' => $values, 'targetEntry' => $entry];
    }

    /**
     * 既定の申請者(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。候補は自分が先頭なので、選ぶ代理元が候補に無ければ先頭になる。
     */
    private function applicantOf(AccessContext $context, int $flowId, mixed $applicantId): AccessContext
    {
        $requester = Requester::fromAccessContext($context);
        $candidates = $this->recordApplyFlows->applicantIdsForTarget($requester, $flowId, $context->date);
        $chosen = PositiveIds::of($applicantId);
        $applicantUserId = in_array($chosen, $candidates, true)
            ? $chosen
            : ($candidates[0] ?? throw new LogicException("フロー {$flowId} で申請できる人を確かめた後に候補が無い"));

        return PrincipalContext::of($context, (int) $applicantUserId);
    }
}

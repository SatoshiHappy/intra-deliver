<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use App\Support\PositiveIds;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 写しの値の入口の読み取り(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 10.3 節)。
 * 書き込みはしない。
 *
 * @phpstan-import-type ApplicationTargetValueSet from ApplicationTargetValues
 *
 * @phpstan-type OpenedForm array{flow: WorkflowFlow, case: WorkflowCase|null, record: int|null}
 */
class ApplicationTargetValueQuery
{
    public function __construct(
        private CaseVisibility $visibility,
        private ApplicableFlows $applicableFlows,
        private CaseOperationDecider $decider,
        private RecordApplyFlows $recordApplyFlows,
        private RecordApplyTargets $recordTargets,
        private WorkflowProxies $proxies,
        private RecordFormQuery $forms,
        private ApplicationTargetValues $applicationTargetValues,
    ) {}

    /**
     * @param  mixed  $flowCode  クエリ flow
     * @param  mixed  $caseId  クエリ case(あれば flow と record を読まない)
     * @param  mixed  $applicantId  クエリ applicant
     * @param  mixed  $recordId  クエリ record(正の整数でなければ無いのと同じ)
     * @return ApplicationTargetValueSet
     *
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowCase|Record> 404 の行(10.3 節の表)
     * @throws AuthorizationException 403 の行(同じ表)
     */
    public function valuesOf(
        AccessContext $context,
        int $applicationTargetId,
        mixed $flowCode,
        mixed $caseId,
        mixed $applicantId,
        mixed $recordId,
    ): array {
        $form = $this->formOf($context, $flowCode, $caseId, $recordId);
        $applicationTarget = $this->applicationTargetValues->applicationTargetOf($form['flow'])
            ?? throw (new ModelNotFoundException)->setModel(WorkflowFlow::class, [$form['flow']->id]);
        $requester = $this->requesterOf($context, $form, $applicantId);
        $date = $context->date;

        if (! $this->canOpen($requester, $form, $date)) {
            throw new AuthorizationException($this->updates($form) && $form['case'] !== null
                ? RecordFormQuery::NOT_ALLOWED_MESSAGE : CaseEntryGate::NOT_APPLICABLE_MESSAGE);
        }

        if (! $this->forms->allows($requester, $applicationTarget['module'], RecordModuleAction::Read, $date)) {
            throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
        }

        $moduleCode = $this->moduleCodeOf($form['flow']);

        if ($form['record'] !== null) {
            $this->recordTargets->requireRecord($requester, $moduleCode, $form['record'], $date);
        }

        $action = $this->updates($form) ? RecordModuleAction::Update : RecordModuleAction::Create;
        $definition = $this->forms->definition($requester, $moduleCode, $action, $date);

        return $this->applicationTargetValues
            ->valuesFor($requester, $applicationTarget, $definition, $applicationTargetId, $date, $context->localeCode)
            ?? throw (new ModelNotFoundException)->setModel(Record::class, [$applicationTargetId]);
    }

    /**
     * フォームのフロー(案件 → フローの順。案件は自分が閲覧できるものだけ)と、そのものを申請するフォームの申請書のレコード。
     *
     * @return OpenedForm
     *
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowCase>
     */
    private function formOf(AccessContext $context, mixed $flowCode, mixed $caseId, mixed $recordId): array
    {
        if ($caseId !== null) {
            $query = WorkflowCase::query()->whereKey(PositiveIds::of($caseId) ?? 0);
            $requester = Requester::fromAccessContext($context);
            $case = $this->visibility->visible($query, $requester, $context->date)->firstOrFail();
            $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->findOrFail($case->workflow_flow_id);

            return ['flow' => $flow, 'case' => $case, 'record' => null];
        }

        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')
            ->where('code', is_string($flowCode) ? $flowCode : '')->firstOrFail();

        return ['flow' => $flow, 'case' => null, 'record' => PositiveIds::of($recordId)];
    }

    /**
     * 判定を求める者(クエリ applicant の代理元か自分。参照項目の候補の入口と同じ。設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節)。
     *
     * @param  OpenedForm  $form
     */
    private function requesterOf(AccessContext $context, array $form, mixed $applicantId): Requester
    {
        $requester = Requester::fromAccessContext($context);
        $principalId = PositiveIds::of($applicantId);

        if ($principalId === null || $context->userId === null || $principalId === $context->userId) {
            return $requester;
        }

        $principals = $this->proxies
            ->principalIdsOf($context->userId, $form['flow']->id, WorkflowProxyKind::Apply, $context->date);
        $principal = RecordApplyFlows::requesterAs($requester, $principalId);

        $opens = in_array($principalId, $principals, true) && $this->canOpen($principal, $form, $context->date);

        return $opens ? $principal : $requester;
    }

    /**
     * このフォームを開けるか(案件のフォームは、参照項目の候補の入口の「代理元としてフォームを開ける」と同じく案件の申請者に限る)。
     *
     * @param  OpenedForm  $form
     */
    private function canOpen(Requester $person, array $form, DateTimeInterface $date): bool
    {
        $flowId = $form['flow']->id;
        $case = $form['case'];

        if ($case !== null) {
            return $case->user_id === $person->userId && ($case->state === WorkflowCaseState::Draft
                ? $this->applicableFlows->isApplicable($person, $date, $flowId)
                : $this->decider->isUpdatable($person, $date, $flowId));
        }

        return $form['record'] !== null
            ? $this->recordApplyFlows->allows($person, $flowId, $date)
            : $this->applicableFlows->isApplicable($person, $date, $flowId);
    }

    /**
     * 申請書の定義を update で引くフォーム(そのものを申請する・再申請)。
     *
     * @param  OpenedForm  $form
     */
    private function updates(array $form): bool
    {
        return $form['case'] !== null ? $form['case']->state !== WorkflowCaseState::Draft : $form['record'] !== null;
    }

    private function moduleCodeOf(WorkflowFlow $flow): string
    {
        return $flow->workflowContent->recordModule->code
            ?? throw new LogicException("フロー {$flow->code} のコンテンツの業務モジュールを読み込んでいない");
    }
}

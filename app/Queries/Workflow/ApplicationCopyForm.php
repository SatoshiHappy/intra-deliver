<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 案件からの複製の画面(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.3 節、写す値.md の 13 節):
 * 新規の申請の画面に、元の申請の最後の申請・再申請の版から写した初期値と props copy を足す。403 はフローの単位で元の申請の有無を漏らさず、
 * 404 の段は元の申請のレコードの値を読む前に置く。主体の判定をしない「版から」は写す人の閲覧の判定の後にだけ呼び、組み立ての最後に閲覧をもう 1 回見る。
 *
 * @phpstan-import-type ApplyForm from ApplicationFormQuery
 * @phpstan-import-type ApplicationTargetValueSet from ApplicationTargetValues
 */
class ApplicationCopyForm
{
    public function __construct(
        private CaseEntryGate $gate,
        private CaseVisibility $visibility,
        private ApplicableFlows $applicableFlows,
        private ApplicationApplicants $applicants,
        private RecordFormQuery $forms,
        private ApplicationFormQuery $applicationForms,
        private ApplicationTargetValues $applicationTargetValues,
        private CopiedRowAccess $copiedRowAccess,
    ) {}

    /**
     * @param  int  $sourceCaseId  クエリ copy(元の申請)
     * @param  mixed  $applicantId  クエリ applicant(選ぶ代理元)
     * @param  mixed  $baseDate  クエリ base_date
     * @return ApplyForm
     *
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowCase|RecordRevision> フローが無い・元の申請が無い・一時保存・ほかのフロー・
     *                                                                          写す人が閲覧できない・写す元を引けないとき
     * @throws AuthorizationException 自分か代理元のどれもこのフローを申請できないとき(ゲストを含む)
     * @throws LogicException 写す元の版が写す先の業務モジュールのものでないとき(起きない = 写す値.md の 13.1 節)
     */
    public function fromCase(
        AccessContext $context,
        string $flowCode,
        int $sourceCaseId,
        mixed $applicantId,
        mixed $baseDate = null,
    ): array {
        $this->gate->authorizeFlow($context, $flowCode);
        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->where('code', $flowCode)->firstOrFail();
        $source = $this->visibleSource($context, $flow->id, $sourceCaseId);
        $requester = Requester::fromAccessContext($context);
        $candidates = $this->applicableFlows->applicantIds($requester, $flow->id, $context->date);
        [$applicant, $applicants] = $this->applicants->choose($context, $candidates, $applicantId);
        $judge = Requester::fromAccessContext($applicant);
        $moduleCode = $flow->workflowContent->recordModule->code
            ?? throw new LogicException("フロー {$flow->code} のコンテンツの業務モジュールを読み込んでいない");
        $module = $this->forms->definition($judge, $moduleCode, RecordModuleAction::Create, $context->date);
        $copied = $this->forms->copyFromRevision(
            $judge, $moduleCode, $this->sourceRevisionId($source), $context->date, $context->localeCode,
        );
        $name = $source->name ?? throw new LogicException("申請した案件 {$source->id} が案件名を持たない");
        $number = $source->case_number ?? throw new LogicException("申請した案件 {$source->id} が案件番号を持たない");
        $form = $this->applicationForms->newForm($context, $applicant, $flow, $module, $baseDate, $applicants);
        $initial = ['name' => $name, 'comment' => null, 'values' => $copied['values'], 'details' => $copied['details']];
        $targetValues = $this->targetValuesOf($judge, $flow, $moduleCode, $module, $copied['values'], $context);
        $restriction = $copied['rowAccess'] ?? ['restricted' => false, 'viewers' => []];
        $copy = [
            'caseId' => $source->id,
            'caseNumber' => CaseNumbers::format($number),
            'name' => $name,
            'clearedFields' => $copied['clearedFields'],
            'rowAccess' => $this->copiedRowAccess->seal($restriction, $context->userId, $flow->id),
        ];
        $this->visibleSource($context, $flow->id, $source->id);

        return [...$form, 'initial' => $initial, 'targetValues' => $targetValues, 'copy' => $copy];
    }

    /**
     * 写す人(今の利用者)が閲覧できる、このフローの一時保存でない案件(404 の行はどれも同じ形 = 12.3 節の判定の順の表)。
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・一時保存・ほかのフロー・閲覧できないとき
     */
    private function visibleSource(AccessContext $context, int $flowId, int $caseId): WorkflowCase
    {
        $query = WorkflowCase::query()->whereKey($caseId)->where('workflow_flow_id', $flowId)
            ->where('state', '<>', WorkflowCaseState::Draft);

        $requester = Requester::fromAccessContext($context);

        return $this->visibility->visible($query, $requester, $context->date)->firstOrFail();
    }

    /**
     * 写す元 = 最後の申請・再申請の履歴の行が指す版(写す値.md の 13.1 節。要求から受けず履歴の行から作る)。判定の後に申請の削除で
     * 履歴が消えたときは 404(12.3 節の判定の後の表の 1 行目)。
     *
     * @throws ModelNotFoundException<RecordRevision> 写す元を引けないとき
     */
    private function sourceRevisionId(WorkflowCase $source): int
    {
        $revisionId = WorkflowCaseHistory::query()->where('workflow_case_id', $source->id)
            ->whereIn('action', CaseHistories::SUBMISSIONS)->orderByDesc('id')->value('record_revision_id');

        return is_int($revisionId) ? $revisionId : throw (new ModelNotFoundException)->setModel(RecordRevision::class);
    }

    /**
     * 写した申請対象が残っていれば、既定の申請者が閲覧できるときだけ写す値(写す値.md の 13.4 節。閲覧できなければ null で 403 にしない)。
     *
     * @param  array<string, mixed>  $module  申請書の業務モジュールの定義
     * @param  array<string, mixed>  $values  写した値(画面の初期値の形)
     * @return ApplicationTargetValueSet|null
     */
    private function targetValuesOf(
        Requester $judge,
        WorkflowFlow $flow,
        string $moduleCode,
        array $module,
        array $values,
        AccessContext $context,
    ): ?array {
        $applicationTarget = $this->applicationTargetValues->applicationTargetOf($flow);
        $targetId = $applicationTarget === null
            ? null
            : $this->applicationTargetValues->applicationTargetIdOf($applicationTarget, $values);

        return $applicationTarget === null || $targetId === null ? null : $this->applicationTargetValues->valuesFor(
            $judge, $applicationTarget, $moduleCode, $module, $targetId, null, $context->date, $context->localeCode,
        );
    }
}

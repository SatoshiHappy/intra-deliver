<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordCandidates;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use App\Support\PositiveIds;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 申請の画面の参照項目の候補(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節。クエリ record の行 =
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 1 節): フォームの外の業務モジュールを引かせず、判定を求める者で
 * 業務データの入れ物の公開の口 {@see RecordCandidates} を呼ぶ。応答の形は業務データの入れ物の画面の候補と同じ。鍵を取らない。
 *
 * @phpstan-type CandidateQuery array{flow: mixed, case: mixed, applicant: mixed, record: mixed}
 * @phpstan-type CandidateForm array{flow: WorkflowFlow, case: WorkflowCase|null, record: int|null}
 */
class ApplicationRecordCandidates
{
    public function __construct(
        private CaseVisibility $visibility,
        private WorkflowProxies $proxies,
        private ApplicableFlows $applicableFlows,
        private CaseOperationDecider $decider,
        private RecordApplyFlows $recordApplyFlows,
        private RecordApplyTargets $recordTargets,
        private RecordFormQuery $forms,
        private RecordCandidates $candidates,
    ) {}

    /**
     * 4.5 節の判定の 404・403 を確かめ、判定を求める者を返す。入口が入力の検証より前に呼ぶ(判定の順を入力の 422 より先にするため)。
     *
     * @param  CandidateQuery  $query
     *
     * @throws ModelNotFoundException<WorkflowCase|WorkflowFlow|RecordModule> 案件が無い・閲覧できない、フローが無い、フォームの参照先でないとき
     * @throws AuthorizationException 判定を求める者が参照先の read を許されないとき
     */
    public function requesterOf(AccessContext $context, string $moduleCode, array $query): Requester
    {
        $form = $this->formOf($context, $query);

        if (! $this->isReferenced($form['flow'], $moduleCode)) {
            throw (new ModelNotFoundException)->setModel(RecordModule::class, [$moduleCode]);
        }

        $requester = $this->requesterByApplicant($context, $form, $query['applicant']);

        if (! $this->forms->allows($requester, $moduleCode, RecordModuleAction::Read, $context->date)) {
            throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
        }

        return $requester;
    }

    /**
     * 候補({@see self::requesterOf()} と同じ判定の後。語と除くレコードは入口が検証した値)。
     *
     * @param  CandidateQuery  $query
     * @return list<array{id: int, label: string|null}>
     *
     * @throws ModelNotFoundException<WorkflowCase|WorkflowFlow|RecordModule>
     * @throws AuthorizationException
     */
    public function candidates(
        AccessContext $context,
        string $moduleCode,
        array $query,
        string $keyword,
        ?int $excludedId,
    ): array {
        $requester = $this->requesterOf($context, $moduleCode, $query);

        return $this->candidates->candidates($requester, $moduleCode, $keyword, $excludedId, $context->date);
    }

    /**
     * 候補を引くフォーム(4.5 節。クエリ record = 既存のデータからの申請/README.md の 1 節)。
     *
     * @param  CandidateQuery  $query
     * @return CandidateForm
     *
     * @throws ModelNotFoundException<WorkflowCase|WorkflowFlow>
     */
    private function formOf(AccessContext $context, array $query): array
    {
        if ($query['case'] !== null) {
            $cases = WorkflowCase::query()->whereKey(PositiveIds::of($query['case']) ?? 0);
            $requester = Requester::fromAccessContext($context);
            $case = $this->visibility->visible($cases, $requester, $context->date)->firstOrFail();
            $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->findOrFail($case->workflow_flow_id);

            return ['flow' => $flow, 'case' => $case, 'record' => null];
        }

        $flowCode = is_string($query['flow']) ? $query['flow'] : '';
        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->where('code', $flowCode)->firstOrFail();

        return ['flow' => $flow, 'case' => null, 'record' => PositiveIds::of($query['record'])];
    }

    /**
     * 判定を求める者(4.5 節)。解釈できない値・代理元でない値も自分にし、申請の画面の GET の既定の申請者と同じ読みにする。
     *
     * @param  CandidateForm  $form
     */
    private function requesterByApplicant(AccessContext $context, array $form, mixed $applicantId): Requester
    {
        $requester = Requester::fromAccessContext($context);
        [$principalId, $userId] = [PositiveIds::of($applicantId), $context->userId];

        if ($principalId === null || $userId === null || $principalId === $userId) {
            return $requester;
        }

        $flowId = $form['flow']->id;
        $principal = RecordApplyFlows::requesterAs($requester, $principalId);
        $isPrincipal = $this->proxies
            ->isPrincipalOf($userId, $principalId, WorkflowProxyKind::Apply, $flowId, $context->date);

        return $isPrincipal && $this->opens($requester, $principal, $form, $context->date) ? $principal : $requester;
    }

    /**
     * 代理元としてフォームを開けるか(4.5 節、クエリ record = 既存のデータからの申請/README.md の 1 節)。新規は申請者の選択肢と同じ式で求め、
     * 選択肢にいない代理元で候補を引かせない。
     *
     * @param  CandidateForm  $form
     */
    private function opens(Requester $requester, Requester $principal, array $form, DateTimeInterface $date): bool
    {
        [$flow, $case, $recordId] = [$form['flow'], $form['case'], $form['record']];
        $principalId = (int) $principal->userId;

        if ($case !== null) {
            return $case->user_id === $principalId && $this->decider->isUpdatable($principal, $date, $flow->id);
        }

        if ($recordId === null) {
            return in_array($principalId, $this->applicableFlows->applicantIds($requester, $flow->id, $date), true);
        }

        $moduleCode = (string) $flow->workflowContent?->recordModule?->code;

        $creatorId = $this->recordTargets->creatorAmong($requester, [$principalId], $moduleCode, $recordId, $date);

        return $this->recordApplyFlows->allows($principal, $flow->id, $date) && $creatorId === $principalId;
    }

    /**
     * その業務モジュールが、フォームのコンテンツの業務モジュール(明細を含む)の参照の項目の参照先か。
     */
    private function isReferenced(WorkflowFlow $flow, string $moduleCode): bool
    {
        $formModuleId = (int) $flow->workflowContent?->record_module_id;
        $childIds = RecordField::query()->where('record_module_id', $formModuleId)
            ->whereNotNull('child_record_module_id')->pluck('child_record_module_id')->all();

        return RecordField::query()->whereIn('record_module_id', [$formModuleId, ...$childIds])
            ->whereHas('referencedRecordModule', fn (Builder $module) => $module->where('code', $moduleCode))
            ->exists();
    }
}

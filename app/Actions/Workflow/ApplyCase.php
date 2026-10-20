<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\CreateRecord;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Master\Api\CurrentDepartment;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\PrincipalContext;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 申請(新規・一時保存から)(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.1。判定の順と書く順はそこが正本)。
 * レコードの登録(CreateRecord)の 404・403・422 は受け止めずに伝わり、外側ごと巻き戻る(案件とレコードを食い違わせないため)。
 * 鍵の順 = データモデル/直列化と検査.md 4.1、申請の代理で申請者として判定する所 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節。
 * 業務データの入れ物の口には版の契機を渡し、口が書いた版を履歴の行が指す(設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。
 *
 * @phpstan-type Application array{columns: array<string, mixed>, comment: string|null, revisionId: int,
 *     actor: CaseActor}
 */
class ApplyCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseProxyChecks $proxyChecks,
        private CaseInputRules $inputRules,
        private ApplicationBaseDate $baseDates,
        private CaseRouteCopier $copier,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
        private ApplicationRecords $records,
        private CreateRecord $createRecord,
        private CurrentDepartment $currentDepartment,
    ) {}

    /**
     * 新規の申請(一時保存を経ない)。
     *
     * @param  string|null  $baseDate  申請基準日の入力(業務処理は既定を持たない = 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 13 節)
     * @param  int|null  $applicantUserId  申請者(申請の代理の代理元。null = 自分)
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき(業務データの入れ物の 404 も伝わる)
     * @throws AuthorizationException 申請できる人でないとき(ゲストを含む。業務データの入れ物の 403 も伝わる)
     * @throws ValidationException 案件名(name)・コメント(comment)・申請基準日(base_date)・展開(case)・申請者(applicant_user_id)の誤り、
     *                             業務データの入れ物の値の誤り
     */
    public function execute(
        AccessContext $context,
        string $flowCode,
        ApplicationInput $input,
        ?string $baseDate,
        ?int $applicantUserId = null,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $flowCode, $input, $baseDate, $applicantUserId) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->sharedLock()->firstOrFail();
            $applicant = $this->proxyChecks->applicantOf($context, $flow->id, $applicantUserId);
            $this->guard->requireApplicable(PrincipalContext::of($context, $applicant), $flow->id);
            $this->guard->permitWithoutCase(WorkflowOperation::Apply);
            $application = $this->prepare($context, $applicant, $flow, $input, $baseDate);

            $case = WorkflowCase::query()->create(['workflow_flow_id' => $flow->id, 'revision' => 0, ...$application['columns']]);

            return $this->writeNodes($context, $case, $application);
        });
    }

    /**
     * 一時保存からの申請(同じ行が未完了になる)。申請に使うのは一時保存の入力でなく、渡した入力。
     *
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  string|null  $baseDate  申請基準日の入力(一時保存の申請基準日は画面が初期値にするだけ)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき(業務データの入れ物の 404 も伝わる)
     * @throws AuthorizationException 申請できる人でないとき(業務データの入れ物の 403 も伝わる)
     * @throws ValidationException 改訂番号・表・展開(case)、案件名(name)・コメント(comment)・申請基準日(base_date)の誤り、業務データの入れ物の値の誤り
     */
    public function executeFromDraft(AccessContext $context, int $caseId, int $revision, ApplicationInput $input, ?string $baseDate): CaseOperationResult
    {
        return DB::transaction(function () use ($context, $caseId, $revision, $input, $baseDate) {
            $authorize = fn (WorkflowCase $case) => $this->guard->requireApplicable(
                $this->proxyChecks->applicantJudgeOf($context, $case),
                $case->workflow_flow_id,
            );
            $permit = $this->guard->lockAndPermit(WorkflowOperation::Apply, $context, $caseId, $revision, $authorize);
            $case = $permit->case();
            $application = $this->prepare($context, $permit->processorUserId(), $permit->flow(), $input, $baseDate);

            $this->writer->advance($case, ['draft_input' => null, ...$application['columns']]);

            return $this->writeNodes($context, $case, $application);
        });
    }

    /**
     * 判定の順の 7〜8 と案件番号(操作.md 7 節)。申請基準日の規則・レコードの登録・担当組織は申請者で決める
     * (代理先が申請しても案件の値を代理元のものにするため = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節)。
     *
     * @param  int  $applicantUserId  申請者(処理者)
     * @return Application
     */
    private function prepare(AccessContext $context, int $applicantUserId, WorkflowFlow $flow, ApplicationInput $input, ?string $baseDate): array
    {
        $applicant = PrincipalContext::of($context, $applicantUserId);
        [$name, $comment] = $this->inputRules->nameAndComment($input->name, $input->comment);
        [$date, $versionId] = $this->baseDates->resolve($applicant, $flow->id, $baseDate);
        $actor = CaseActor::forProcessor($applicantUserId, $context);
        $origin = $this->records->origin(WorkflowAction::Apply, $this->records->applyNodeName($versionId), $actor);
        $moduleCode = $this->records->moduleCodeOf($flow);
        $recordId = $this->createRecord->execute($applicant, $moduleCode, $input->record, $origin);
        $revisionId = $this->records->revisionOf($recordId);
        $caseNumber = (int) DB::scalar("select nextval('workflow_case_number_seq')");

        return [
            'columns' => [
                'state' => WorkflowCaseState::Active,
                'name' => $name ?? $this->records->defaultName($flow, $recordId),
                'case_number' => $caseNumber,
                'user_id' => $applicantUserId,
                'department_id' => $this->currentDepartment->departmentIdOn($applicantUserId, $applicant->selectedDepartmentId, $date),
                'record_id' => $recordId,
                'applied_at' => now(),
                'workflow_flow_version_id' => $versionId,
                'base_date' => $date->toDateString(),
            ],
            'comment' => $comment,
            'revisionId' => $revisionId,
            'actor' => $actor,
        ];
    }

    /**
     * 版のルートを写し、申請のノードから計画して書く(案件の行は最初に書いてあるので、改訂番号は進めない = 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節の最後の箇条)。
     *
     * @param  Application  $application
     */
    private function writeNodes(AccessContext $context, WorkflowCase $case, array $application): CaseOperationResult
    {
        $copied = $this->copier->copy($case, $case->workflow_flow_version_id ?? throw new LogicException("案件 {$case->id} に写した版が無い"));
        $applyNodes = array_filter($copied, fn (WorkflowCaseNode $node) => $node->kind === WorkflowNodeKind::Apply);
        $applyNode = reset($applyNodes) ?: throw new LogicException("案件 {$case->id} に申請のノードが無い");
        $actor = $application['actor'];
        $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $case, $applyNode->id, WorkflowAction::Apply));

        $comment = $application['comment'];

        return $this->writer->writePlan($plan, $actor, $comment, $application['revisionId'], advanceRevision: false);
    }
}

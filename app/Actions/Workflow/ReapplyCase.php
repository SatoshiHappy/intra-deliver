<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\PrincipalContext;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 再申請(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.6、業務データとの接続/申請と書き込み.md 3・4 節。判定の順と手順の正本はそこ)。
 * レコードの更新(UpdateRecord)の 404・403・422 は受け止めずに伝わり、外側ごと巻き戻る(案件とレコードを食い違わせないため)。
 * 代理元として再申請するときの判定する人 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節。
 * UpdateRecord には版の契機を渡し、口が書いた版を履歴の行が指す(設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。
 * 添付は同じ UpdateRecord で付け(版 1 つ)、外す指定は受けない(付いている添付は残る = 設計/ワークフロー/申請の周辺/添付/README.md の 2 節)。
 */
class ReapplyCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
        private ApplicationRecords $records,
        private UpdateRecord $updateRecord,
        private CaseAttachmentRules $attachmentRules,
        private ContactValidator $contactValidator,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     * @param  CaseContact|null  $contact  連絡(規則 = {@see ContactValidator}。誤りは 422 contact.user_ids・contact.message)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき(業務データの入れ物の 404 も伝わる)
     * @throws AuthorizationException 業務モジュールの update を許されないとき
     * @throws ValidationException 改訂番号・表・展開(case)、案件名(name)・コメント(comment)・処理担当者(principal_user_id)・
     *                             添付(attachments・attachments.<添字>)の誤り、業務データの入れ物の値の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        ApplicationInput $input,
        ?int $principalUserId = null,
        ?CaseContact $contact = null,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $caseId, $revision, $input, $principalUserId, $contact) {
            $authorize = fn (WorkflowCase $case) => $this->guard->requireUpdatable($context, $case);
            $permit = $this->guard->lockAndPermit(WorkflowOperation::Reapply, $context, $caseId, $revision, $authorize, null, $principalUserId);
            $case = $permit->case();
            [$name, $comment] = $this->inputRules->nameAndComment($input->name, $input->comment);
            $contact = $this->contactValidator->validated($contact, $context);
            $flow = WorkflowFlow::query()->findOrFail($case->workflow_flow_id);
            $this->attachmentRules->rejectFiles(WorkflowOperation::Reapply, $flow, $input->attachFiles, $case);
            $recordId = $case->record_id ?? throw new LogicException("未完了の案件 {$case->id} がレコードを指していない");
            $principal = PrincipalContext::of($context, $permit->processorUserId());
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $origin = $this->records->origin(WorkflowAction::Reapply, $permit->node()->displayName(), $actor);
            $holder = WorkflowRecordHolds::holderOf($case->id);
            $moduleCode = $this->records->moduleCodeOf($flow);
            $record = $input->recordInput();
            $this->updateRecord
                ->execute($principal, $moduleCode, $recordId, $record, version: null, holder: $holder, origin: $origin);
            $revisionId = $this->records->revisionOf($recordId);
            $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $case, $permit->node()->id, WorkflowAction::Reapply));

            $columns = ['name' => $name ?? $this->records->defaultName($flow, $recordId)];

            $result = $this->writer->writePlan($plan, $actor, $comment, $revisionId, columns: $columns);

            $this->notices->execute($result, $contact, $context->date);

            return $result;
        });
    }
}

<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 一時保存(上書き)(docs の 設計/ワークフロー/案件の操作/操作.md 3.4。判定の順は 3.1 の 2、代理での判定する人は 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.8 節)。
 * 添付は外す指定の行を消してから足す(外せるのは実行者が上げた行だけ。書く順 = 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節)。
 */
class UpdateDraft
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseProxyChecks $proxyChecks,
        private CaseInputRules $inputRules,
        private CaseAttachmentRules $attachmentRules,
        private CaseDraftAttachments $attachments,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 申請できる人でないとき
     * @throws ValidationException 改訂番号・表(case)、一時保存の入力(draft)・添付(attachments・attachments.<添字>・
     *                             remove_attachment_ids)の誤り
     * @throws RuntimeException 添付の実体を書けなかったとき
     */
    public function execute(AccessContext $context, int $caseId, int $revision, DraftInput $input): void
    {
        DB::transaction(function () use ($context, $caseId, $revision, $input): void {
            $authorize = fn (WorkflowCase $case) => $this->guard->requireApplicable(
                $this->proxyChecks->applicantJudgeOf($context, $case),
                $case->workflow_flow_id,
            );
            $permit = $this->guard->lockAndPermit(WorkflowOperation::OverwriteDraft, $context, $caseId, $revision, $authorize);
            $case = $permit->case();
            $draft = $this->inputRules->draftInput($input);
            $flow = WorkflowFlow::query()->findOrFail($case->workflow_flow_id);
            $this->attachmentRules->rejectFiles(WorkflowOperation::OverwriteDraft, $flow, $input->attachFiles);
            $uploader = CaseActor::forProcessor($permit->processorUserId(), $context)->executorUserId;
            $removals = $this->attachmentRules->removals($case, $input->removeAttachmentIds, $uploader);

            $case->update(['draft_input' => $draft, 'revision' => $case->revision + 1]);
            $this->attachments->remove($removals);
            $this->attachments->add($case, $uploader, $input->attachFiles);
        });
    }
}

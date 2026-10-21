<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\PrincipalContext;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 一時保存(新規)(docs の 設計/ワークフロー/案件の操作/操作.md 3.4。判定の順は 3.1 の 2、代理元の確かめの位置は 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.8 節)。
 * レコードの値は検証しない(一時保存は途中の入力を残すためのもので、業務データの入れ物を呼ばない)。
 * 添付は案件の行を書いた後に上げて一時保存の添付の行にする(上げた人 = 実行者。書く順 = 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節)。
 */
class CreateDraft
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseProxyChecks $proxyChecks,
        private CaseInputRules $inputRules,
        private CaseAttachmentRules $attachmentRules,
        private CaseDraftAttachments $attachments,
    ) {}

    /**
     * @param  int|null  $applicantUserId  申請者(申請の代理の代理元。null = 自分)
     * @return int 作った案件の id
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     * @throws AuthorizationException 申請できる人でないとき(ゲストを含む)
     * @throws ValidationException 一時保存の入力(draft)・申請者(applicant_user_id)・添付(attachments・attachments.<添字>)の誤り
     * @throws RuntimeException 添付の実体を書けなかったとき
     */
    public function execute(
        AccessContext $context,
        string $flowCode,
        DraftInput $input,
        ?int $applicantUserId = null,
    ): int {
        return DB::transaction(function () use ($context, $flowCode, $input, $applicantUserId) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->firstOrFail();
            $applicant = $this->proxyChecks->applicantOf($context, $flow->id, $applicantUserId);
            $this->guard->requireApplicable(PrincipalContext::of($context, $applicant), $flow->id);
            $this->guard->permitWithoutCase(WorkflowOperation::SaveDraft);
            $draft = $this->inputRules->draftInput($input);
            $this->attachmentRules->rejectFiles(WorkflowOperation::SaveDraft, $flow, $input->attachFiles);

            $case = WorkflowCase::query()->create([
                'workflow_flow_id' => $flow->id,
                'state' => WorkflowCaseState::Draft,
                'user_id' => $applicant,
                'draft_input' => $draft,
                'revision' => 0,
            ]);
            $uploader = CaseActor::forProcessor($applicant, $context)->executorUserId;
            $this->attachments->add($case, $uploader, $input->attachFiles);

            return $case->id;
        });
    }
}

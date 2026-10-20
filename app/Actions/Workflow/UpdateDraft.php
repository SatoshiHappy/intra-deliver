<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 一時保存(上書き)(docs の 設計/ワークフロー/案件の操作/操作.md 3.4。判定の順は 3.1 の 2、代理での判定する人は 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.8 節)。
 */
class UpdateDraft
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseProxyChecks $proxyChecks,
        private CaseInputRules $inputRules,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 申請できる人でないとき
     * @throws ValidationException 改訂番号・表(case)、一時保存の入力(draft)の誤り
     */
    public function execute(AccessContext $context, int $caseId, int $revision, DraftInput $input): void
    {
        DB::transaction(function () use ($context, $caseId, $revision, $input): void {
            $authorize = fn (WorkflowCase $case) => $this->guard->requireApplicable(
                $this->proxyChecks->applicantJudgeOf($context, $case),
                $case->workflow_flow_id,
            );
            $case = $this->guard->lockAndPermit(WorkflowOperation::OverwriteDraft, $context, $caseId, $revision, $authorize)->case();
            $draft = $this->inputRules->draftInput($input);

            $case->update(['draft_input' => $draft, 'revision' => $case->revision + 1]);
        });
    }
}

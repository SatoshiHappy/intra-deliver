<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 取止め(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.6。判定の順はそこが正本)。書き込みは {@see CaseWriter::writePlan()}。
 * レコードには触れない(取止めの後は業務データの画面から変えられる)。
 */
class WithdrawCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・表(case)、コメント(comment)の誤り、
     *                             処理担当者(principal_user_id)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        ?string $comment,
        ?int $principalUserId = null,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $caseId, $revision, $comment, $principalUserId) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::Withdraw,
                $context,
                $caseId,
                $revision,
                principalUserId: $principalUserId,
            );
            $comment = $this->inputRules->comment($comment);
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $permit->case(), $permit->node()->id, WorkflowAction::Withdraw));

            return $this->writer->writePlan($plan, $actor, $comment);
        });
    }
}

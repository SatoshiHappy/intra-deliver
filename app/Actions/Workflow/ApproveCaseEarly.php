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
 * 承認終了(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.3。判定の順と完了の片付けはそこが正本)。
 * 書き込みは {@see CaseWriter::writePlan()}。
 */
class ApproveCaseEarly
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(案件のノードの id)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表・申請者承認防止(case)、コメント(comment)の誤り、
     *                             処理担当者(principal_user_id)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?string $comment,
        ?int $principalUserId = null,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $caseId, $revision, $nodeId, $comment, $principalUserId) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::ApproveEarly,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $this->guard->rejectApplicant($permit, $actor);
            $comment = $this->inputRules->comment($comment);
            $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $permit->case(), $nodeId, WorkflowAction::ApproveEarly));

            return $this->writer->writePlan($plan, $actor, $comment);
        });
    }
}

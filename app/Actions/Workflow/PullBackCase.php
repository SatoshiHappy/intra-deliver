<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\PullBackableNodes;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 引戻し(差戻しの引戻しを含む。docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.5。判定の順と戻し方はそこが正本)。
 * 引戻せるかは {@see PullBackableNodes} の 1 規則(画面の pullBackNodes と業務処理が食い違わないため)。
 */
class PullBackCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
        private ContactValidator $contactValidator,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  引戻すノード(処理者が処理したノード。案件のノードの id)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     * @param  CaseContact|null  $contact  連絡(規則 = {@see ContactValidator}。誤りは 422 contact.user_ids・contact.message)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表・戻す相手(case)、コメント(comment)の誤り、
     *                             処理担当者(principal_user_id)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?string $comment,
        ?int $principalUserId = null,
        ?CaseContact $contact = null,
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $caseId, $revision, $nodeId, $comment, $principalUserId, $contact,
        ) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::PullBack,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $comment = $this->inputRules->comment($comment);
            $contact = $this->contactValidator->validated($contact, $context);
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $reverted = WorkflowCaseHistory::query()->where('workflow_case_node_id', $permit->node()->id)->orderByDesc('id')->firstOrFail();
            $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $permit->case(), $nodeId, WorkflowAction::PullBack, revertedHistory: $reverted));

            $result = $this->writer->writePlan($plan, $actor, $comment);

            $this->notices->execute($result, $contact, $context->date);

            return $result;
        });
    }
}

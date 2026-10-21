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
 * 承認(普通の承認のノード・横配置の子・縦配置の子 = 合議の票。docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.2)。
 * 判定の順と書く順はそこが正本。指定の規則は {@see AssignmentRules}、書き込みは {@see CaseWriter::writePlan()}。
 */
class ApproveCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private AssignmentRules $assignmentRules,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
        private ContactValidator $contactValidator,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(案件のノードの id)
     * @param  array<int, list<int>>  $assignments  指定(指定されるノードの id → ユーザーの id の並び。操作するノードが指定するノードのときだけ)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     * @param  CaseContact|null  $contact  連絡(規則 = {@see ContactValidator}。誤りは 422 contact.user_ids・contact.message)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表・申請者承認防止・展開(case)、コメント(comment)、指定(assignments)の誤り、
     *                             処理担当者(principal_user_id)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?string $comment,
        array $assignments = [],
        ?int $principalUserId = null,
        ?CaseContact $contact = null,
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $caseId, $revision, $nodeId, $comment, $assignments, $principalUserId, $contact,
        ) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::Approve,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $this->guard->rejectApplicant($permit, $actor);
            $comment = $this->inputRules->comment($comment);
            $contact = $this->contactValidator->validated($contact, $context);
            $assignments = $this->assignmentRules->validated($permit->case(), $nodeId, $assignments);
            $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $permit->case(), $nodeId, WorkflowAction::Approve, $assignments));

            $result = $this->writer->writePlan($plan, $actor, $comment, assignments: $assignments);

            $this->notices->execute($result, $contact, $context->date);

            return $result;
        });
    }
}

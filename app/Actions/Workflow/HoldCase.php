<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 保留(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.1 節。判定の順は同じ文書の 7 節)。
 * 書き込みは {@see CaseNodeWriter} に任せる(計画を呼ばない操作の書く順を 1 か所に保つため)。
 */
class HoldCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private CaseNodeWriter $writer,
        private ContactValidator $contactValidator,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(案件のノードの id)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     * @param  CaseContact|null  $contact  連絡(規則 = {@see ContactValidator}。誤りは 422 contact.user_ids・contact.message)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表・申請者承認防止(case)、コメント(comment)、処理担当者(principal_user_id)の誤り
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
                WorkflowOperation::Hold,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $this->guard->rejectApplicant($permit, $actor, holds: true);
            $comment = $this->inputRules->comment($comment);
            $contact = $this->contactValidator->validated($contact, $context);

            $result = $this->writer->write($permit, $actor, WorkflowAction::Hold, $comment, WorkflowNodeState::Hold);

            $this->notices->execute($result, $contact, $context->date);

            return $result;
        });
    }
}

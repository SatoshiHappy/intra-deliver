<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 処理対象者の変更(案件操作。画面の語は処理担当者の変更。docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.3 節。
 * 判定の順は同じ文書の 15.1 節 = {@see CaseManagerGate})。人の規則は {@see RetargetValidator}、書き込みは {@see CaseNodeWriter}。
 */
class RetargetCaseNode
{
    public function __construct(
        private CaseManagerGate $gate,
        private CaseInputRules $inputRules,
        private RetargetValidator $validator,
        private CaseNodeWriter $writer,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(承認のノード。展開の行を含む)
     * @param  list<int>  $userIds  変更後の処理対象者(入力 user_ids)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 案件のフローの operate を許されないとき
     * @throws ValidationException 改訂番号・ノード・表(case)、コメント(comment)、変更後の人(user_ids)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        array $userIds,
        ?string $comment,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $caseId, $revision, $nodeId, $userIds, $comment) {
            $permit = $this->gate->lockAndPermit(WorkflowOperation::Retarget, $context, $caseId, $revision, $nodeId);
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $comment = $this->inputRules->comment($comment);
            $userIds = $this->validator->validated($permit, $actor, $userIds);

            $result = $this->writer->retarget($permit, $actor, WorkflowAction::Retarget, $comment, $userIds);

            $this->notices->execute($result, null, $context->date);

            return $result;
        });
    }
}

<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 指定の変更(案件操作。docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.6 節。判定の順は同じ文書の 15.1 節 = {@see CaseManagerGate})。
 * 前提と人の規則は {@see ReassignValidator}、書き込みは {@see CaseNodeWriter}。処理対象者が増えないので通知は作られない。
 */
class ReassignCaseNode
{
    public function __construct(
        private CaseManagerGate $gate,
        private CaseInputRules $inputRules,
        private ReassignValidator $validator,
        private CaseNodeWriter $writer,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  指定されるノード Y(入力 node_id)
     * @param  list<int>  $userIds  指定する人(入力 user_ids。横配置は処理の順)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 案件のフローの operate を許されないとき
     * @throws ValidationException 改訂番号・ノード・表・前提(case)、コメント(comment)、人(user_ids)の誤り
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
            $permit = $this->gate->lockAndPermit(WorkflowOperation::Reassign, $context, $caseId, $revision, $nodeId);
            $this->validator->requireReassignable($permit->node());
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $comment = $this->inputRules->comment($comment);
            $userIds = $this->validator->validated($permit->case(), $permit->node(), $actor, $userIds);

            $result = $this->writer->reassign($permit, $actor, $comment, $userIds);

            $this->notices->execute($result, null, $context->date);

            return $result;
        });
    }
}

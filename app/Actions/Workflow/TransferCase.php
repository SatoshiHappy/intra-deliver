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
 * 振替(画面の語は担当変更。docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節。判定の順は同じ文書の 7 節)。
 * 振替先の規則は {@see TransferRules}、書き込みは {@see CaseNodeWriter}。
 */
class TransferCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private TransferRules $transferRules,
        private CaseNodeWriter $writer,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(案件のノードの id)
     * @param  int|null  $toUserId  振替先(入力 to_user_id)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表(case)、コメント(comment)、振替先(to_user_id)、処理担当者(principal_user_id)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?int $toUserId,
        ?string $comment,
        ?int $principalUserId = null,
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $caseId, $revision, $nodeId, $toUserId, $comment, $principalUserId,
        ) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::Transfer,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $comment = $this->inputRules->comment($comment);
            $toUserId = $this->transferRules->validated($permit, $actor, $toUserId);

            $waiting = WorkflowNodeState::Waiting;

            return $this->writer->write($permit, $actor, WorkflowAction::Transfer, $comment, $waiting, $toUserId);
        });
    }
}

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
 * 再展開(案件操作。docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.4 節。判定の順は同じ文書の 15.1 節 = {@see CaseManagerGate})。
 * 人は {@see ReexpansionTargets}、書き込みは処理対象者の変更と同じ {@see CaseNodeWriter}。
 */
class ReexpandCaseNode
{
    public function __construct(
        private CaseManagerGate $gate,
        private CaseInputRules $inputRules,
        private ReexpansionTargets $targets,
        private CaseNodeWriter $writer,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(承認のノード。展開の行を含む)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 案件のフローの operate を許されないとき
     * @throws ValidationException 改訂番号・ノード・表・展開の結果(case)、コメント(comment)の誤り
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?string $comment,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $caseId, $revision, $nodeId, $comment) {
            $permit = $this->gate->lockAndPermit(WorkflowOperation::Reexpand, $context, $caseId, $revision, $nodeId);
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $comment = $this->inputRules->comment($comment);
            $userIds = $this->targets->of($permit->case(), $permit->node());

            $result = $this->writer->retarget($permit, $actor, WorkflowAction::Reexpand, $comment, $userIds);

            $this->notices->execute($result, null, $context->date);

            return $result;
        });
    }
}

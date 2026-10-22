<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use App\Queries\Workflow\CaseVisibility;
use App\Queries\Workflow\FlowPermissions;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 案件操作(案件の管理者の操作)の判定の入口(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節)。
 * 操作ごとの業務処理が 1 つのトランザクションの中で呼ぶ。
 */
class CaseManagerGate
{
    public function __construct(
        private CaseVisibility $visibility,
        private FlowPermissions $flowPermissions,
        private CaseOperationDecider $decider,
    ) {}

    /**
     * 案件の行の鍵を取って判定し、判定を通った結果を返す(鍵の強さ = 設計/ワークフロー/案件の運用/データモデル/README.md の 4.1 節)。
     *
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int|null  $nodeId  操作するノード(入力 node_id。案件の削除は null)
     *
     * @throws ModelNotFoundException<WorkflowCase> 無い・閲覧できないとき
     * @throws AuthorizationException 案件のフローの operate を許されないとき
     * @throws ValidationException 改訂番号・ノード・表の誤り(case)
     */
    public function lockAndPermit(
        WorkflowOperation $operation,
        AccessContext $context,
        int $caseId,
        int $revision,
        ?int $nodeId = null,
    ): CaseOperationPermit {
        $requester = Requester::fromAccessContext($context);
        $query = WorkflowCase::query();
        $locked = $operation === WorkflowOperation::DeleteCase
            ? $query->lockForUpdate()
            : $query->lock('for no key update');
        $case = $locked->findOrFail($caseId);

        if (! $this->visibility->isVisible($requester, $context->date, $case->id)) {
            throw (new ModelNotFoundException)->setModel(WorkflowCase::class, [$caseId]);
        }

        if (! $this->flowPermissions->canOperate($requester, $context->date, $case->workflow_flow_id)) {
            throw new AuthorizationException;
        }

        if ($case->revision !== $revision) {
            throw CaseOperationErrors::ofCase(CaseOperationErrors::STALE_REVISION);
        }

        $node = $nodeId === null ? null : $this->nodeOf($case, $nodeId);
        $date = $context->date;
        $situation = $this->decider->situationsOf($requester, $date, [$case])[$case->id];

        if (! $this->decider->permitsIn($operation, $requester, $date, $situation, $nodeId, canOperate: true)) {
            throw CaseOperationErrors::ofCase(CaseOperationErrors::NOT_PERMITTED);
        }

        return new CaseOperationPermit($situation, $node, null);
    }

    /**
     * 操作するノードの行(この案件のノードでなければ 422 case)。
     *
     * @throws ValidationException
     */
    private function nodeOf(WorkflowCase $case, int $nodeId): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->with('parent')->where('workflow_case_id', $case->id)->find($nodeId)
            ?? throw CaseOperationErrors::ofCase(CaseOperationErrors::NOT_PERMITTED);
    }
}

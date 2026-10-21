<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseNode;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 確認(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.4 節)。自分が確認対象者の開いている確認ごとに確認の行を足す。
 * 案件の行・ノード・改訂番号・処理対象者を変えず、通知を送らない(確認は処理に影響しない = 同じ文書の 9 節)。
 */
class ConfirmCase
{
    public function __construct(private CaseOperationGuard $guard) {}

    /**
     * @return list<int> 確認したノードの id(昇順)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 確認できる確認が無いとき(case)
     */
    public function execute(AccessContext $context, int $caseId): array
    {
        return DB::transaction(function () use ($context, $caseId): array {
            $situation = $this->guard->lockForConfirm($context, $caseId);
            $userId = $context->userId ?? throw new LogicException('確認の判定を通った利用者がいない');
            $histories = WorkflowCaseNode::query()->whereIn('id', $situation->confirmNodeIds)->orderBy('id')
                ->pluck('workflow_case_history_id', 'id')->all();

            foreach ($histories as $nodeId => $historyId) {
                WorkflowCaseConfirmation::query()->create([
                    'workflow_case_id' => $caseId, 'workflow_case_node_id' => $nodeId,
                    'workflow_case_history_id' => $historyId, 'user_id' => $userId,
                ]);
            }

            return array_map('intval', array_keys($histories));
        });
    }
}

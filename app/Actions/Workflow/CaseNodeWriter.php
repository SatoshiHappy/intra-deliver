<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Queries\Workflow\CaseActor;

/**
 * 計画を呼ばない操作(保留・保留解除・振替)の書き込みの 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 9 節の書く順)。
 * 到達日時は変えない(ノードは到達し直していない = 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.4 節)。
 */
class CaseNodeWriter
{
    public function __construct(private CaseWriter $writer) {}

    /**
     * @param  WorkflowNodeState  $state  操作の後のノードの状態
     * @param  int|null  $transferToUserId  振替先(振替だけ)
     */
    public function write(
        CaseOperationPermit $permit,
        CaseActor $actor,
        WorkflowAction $action,
        ?string $comment,
        WorkflowNodeState $state,
        ?int $transferToUserId = null,
    ): CaseOperationResult {
        [$case, $node] = [$permit->case(), $permit->node()];
        $history = WorkflowCaseHistory::query()->create([
            'workflow_case_id' => $case->id,
            'workflow_case_node_id' => $node->id,
            'action' => $action,
            'user_id' => $actor->processorUserId,
            'executor_user_id' => $actor->recordedExecutorUserId(),
            'comment' => $comment,
            'node_changes' => [],
        ]);

        if ($transferToUserId !== null) {
            $people = ['workflow_case_id' => $case->id, 'workflow_case_history_id' => $history->id, 'sequence' => 1];
            WorkflowCaseHistoryUser::query()->create([...$people, 'user_id' => $transferToUserId]);
        }

        $node->update(['state' => $state]);

        if ($transferToUserId !== null) {
            // 足してから消す(処理対象者を 1 人以上に保つ = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節)
            $target = ['workflow_case_node_id' => $node->id, 'user_id' => $transferToUserId];
            WorkflowCaseNodeTarget::query()->create($target);
            WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $node->id)
                ->where('user_id', $actor->processorUserId)->delete();
        }

        $this->writer->advance($case);

        // 処理対象者が増えるのは振替の振替先だけ(設計/ワークフロー/案件の運用/案件の操作/操作.md の 11 節)
        $additions = $transferToUserId === null ? [] : [$node->id => [$transferToUserId]];

        return new CaseOperationResult($case->id, $action, $history->id, [], null, $actor, $additions);
    }
}

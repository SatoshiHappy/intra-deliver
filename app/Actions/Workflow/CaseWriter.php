<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseActor;

/**
 * 案件の操作の書く順(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節の 2〜6)の 1 か所。計画を書く操作が
 * {@see self::writePlan()} の同じ順で書く(操作ごとに書く順が食い違わないため)。
 */
class CaseWriter
{
    public function __construct(
        private CaseRoutePlanWriter $planWriter,
        private CaseConfirmOpening $confirmOpening,
    ) {}

    /**
     * 計画を書いて、操作の結果の値を返す(9 節の 2〜6、11 節。確認を開くのはノードを書いた後 = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 9 節)。
     *
     * @param  int|null  $recordRevisionId  業務データの入れ物の口が書いた更新履歴の版(申請・再申請だけ)
     * @param  array<int, list<int>>  $assignments  検証した指定(指定されたノードの id → 人の並び。承認だけ)
     * @param  array<string, mixed>  $columns  案件の行にほかに書く列(申請・再申請の案件名など)
     * @param  bool  $advanceRevision  改訂番号を進めるか(偽 = 申請。案件の行を計画の前に書いてある = 操作.md 9 節の最後の箇条)
     */
    public function writePlan(
        CaseRoutePlan $plan,
        CaseActor $actor,
        ?string $comment,
        ?int $recordRevisionId = null,
        array $assignments = [],
        array $columns = [],
        bool $advanceRevision = true,
    ): CaseOperationResult {
        $case = $plan->case;
        $ids = $this->planWriter->addChildren($plan);
        $history = WorkflowCaseHistory::query()->create([
            'workflow_case_id' => $case->id,
            'workflow_case_node_id' => $plan->processedNodeId,
            'action' => $plan->action,
            'user_id' => $actor->processorUserId,
            'executor_user_id' => $actor->recordedExecutorUserId(),
            'comment' => $comment,
            'send_back_workflow_case_node_id' => $plan->sendBackNodeId,
            'reverted_workflow_case_history_id' => $plan->revertedHistoryId,
            'record_revision_id' => $recordRevisionId,
            'node_changes' => $plan->nodeChanges($ids),
        ]);

        foreach ($assignments as $nodeId => $userIds) {
            foreach ($userIds as $index => $userId) {
                WorkflowCaseAssignment::query()->create(['workflow_case_id' => $case->id, 'workflow_case_history_id' => $history->id, 'workflow_case_node_id' => $nodeId, 'sequence' => $index + 1, 'user_id' => $userId]);
            }
        }

        $this->planWriter->apply($plan, $ids, $history->id);
        $opened = $this->confirmOpening->open($plan);
        $columns = [...$columns, ...($plan->completion === null ? [] : ['state' => WorkflowCaseState::Completed, 'completion_reason' => $plan->completion, 'completed_at' => now()])];

        if ($advanceRevision) {
            $this->advance($case, $columns);
        } elseif ($columns !== []) {
            $case->update($columns);
        }

        $reached = $plan->reachedNodes($ids);

        return new CaseOperationResult(
            $case->id, $plan->action, $history->id, $reached, $plan->completion, $actor, $plan->targetAdditions($ids), $opened,
        );
    }

    /**
     * 案件の行を更新する(改訂番号 +1。状態の変わる案件の操作はどれも案件の行を書く = 設計/ワークフロー/データモデル/直列化と検査.md 3.1。一時保存からの申請もここを通る)。
     *
     * @param  array<string, mixed>  $columns  ほかに変える列
     */
    public function advance(WorkflowCase $case, array $columns = []): void
    {
        $case->update(['revision' => $case->revision + 1, ...$columns]);
    }
}

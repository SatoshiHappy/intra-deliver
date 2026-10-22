<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Queries\Workflow\CaseActor;
use LogicException;

/**
 * 計画を呼ばない操作(保留・保留解除・振替・処理対象者の変更・再展開・指定の変更)の書き込みの 1 か所(docs の
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 9 節の書く順)。到達日時と写した指定は変えない(ノードは到達し直していない =
 * 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.4 節)。
 */
class CaseNodeWriter
{
    public function __construct(private CaseWriter $writer) {}

    /**
     * @param  WorkflowNodeState  $state  操作の後のノードの状態
     * @param  int|null  $transferToUserId  振替先(振替だけ)
     * @param  int|null  $recordRevisionId  添付を付けたときに業務データの入れ物の口が書いた更新履歴の版
     */
    public function write(
        CaseOperationPermit $permit,
        CaseActor $actor,
        WorkflowAction $action,
        ?string $comment,
        WorkflowNodeState $state,
        ?int $transferToUserId = null,
        ?int $recordRevisionId = null,
    ): CaseOperationResult {
        $node = $permit->node();
        $people = $transferToUserId === null ? [] : [$transferToUserId];
        $history = $this->history($permit, $actor, $action, $comment, $people, $recordRevisionId);
        $node->update(['state' => $state]);

        if ($transferToUserId !== null) {
            // 足してから消す(処理対象者を 1 人以上に保つ = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節)
            $target = ['workflow_case_node_id' => $node->id, 'user_id' => $transferToUserId];
            WorkflowCaseNodeTarget::query()->create($target);
            WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $node->id)
                ->where('user_id', $actor->processorUserId)->delete();
        }

        $this->writer->advance($permit->case());

        // 処理対象者が増えるのは振替の振替先だけ(設計/ワークフロー/案件の運用/案件の操作/操作.md の 11 節)
        $additions = $people === [] ? [] : [$node->id => $people];

        return new CaseOperationResult($permit->case()->id, $action, $history->id, [], null, $actor, $additions);
    }

    /**
     * 処理対象者の変更・再展開: 処理対象者の行を全部消して渡した人で書き直し(同じ人を残しても書き直す)、ノードを承認待ちにする(保留なら解く =
     * 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.3 節・15.4 節)。履歴の人 = 渡した人(渡した順)。
     *
     * @param  list<int>  $userIds  操作の後の処理対象者(呼び手が 1 人以上を確かめた)
     */
    public function retarget(
        CaseOperationPermit $permit,
        CaseActor $actor,
        WorkflowAction $action,
        ?string $comment,
        array $userIds,
    ): CaseOperationResult {
        $node = $permit->node();
        $before = ($permit->situation->currentNode($node->id)
            ?? throw new LogicException("処理対象者を変えるノード {$node->id} が現在のノードでない"))->targetUserIds;
        $history = $this->history($permit, $actor, $action, $comment, $userIds);
        $node->update(['state' => WorkflowNodeState::Waiting]);
        WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $node->id)->delete();

        foreach ($userIds as $userId) {
            WorkflowCaseNodeTarget::query()->create(['workflow_case_node_id' => $node->id, 'user_id' => $userId]);
        }

        $this->writer->advance($permit->case());
        $added = array_values(array_diff($userIds, $before));
        sort($added);

        // 処理対象者が増えたノード = 新しく入った人(設計/ワークフロー/案件の運用/案件の操作/操作.md の 11 節)
        $additions = $added === [] ? [] : [$node->id => $added];

        return new CaseOperationResult($permit->case()->id, $action, $history->id, [], null, $actor, $additions);
    }

    /**
     * 指定の変更: 指定されるノード Y の指定の行を、指定の変更の履歴の行(行のノード = Y)を指して書く(前の指定の行は残す。今の指定がこの行になる =
     * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.5 節)。0 人なら行を書かない(0 人を指定した)。処理対象者は増えない。
     *
     * @param  list<int>  $userIds  確かめた人の並び(横配置は処理の順)
     */
    public function reassign(
        CaseOperationPermit $permit,
        CaseActor $actor,
        ?string $comment,
        array $userIds,
    ): CaseOperationResult {
        [$case, $node] = [$permit->case(), $permit->node()];
        $history = $this->history($permit, $actor, WorkflowAction::Reassign, $comment, []);

        foreach ($userIds as $index => $userId) {
            $row = ['workflow_case_id' => $case->id, 'workflow_case_history_id' => $history->id];
            $row = [...$row, 'workflow_case_node_id' => $node->id, 'sequence' => $index + 1];
            WorkflowCaseAssignment::query()->create([...$row, 'user_id' => $userId]);
        }

        $this->writer->advance($case);

        return new CaseOperationResult($case->id, WorkflowAction::Reassign, $history->id, [], null, $actor);
    }

    /**
     * 履歴の行(行のノード以外を変えないので node_changes = 空)と履歴の人(並びの順)。
     *
     * @param  list<int>  $people  履歴の人(振替先・操作の後の処理対象者。無ければ空)
     * @param  int|null  $recordRevisionId  添付を付けたときの更新履歴の版(無ければ null)
     */
    private function history(
        CaseOperationPermit $permit,
        CaseActor $actor,
        WorkflowAction $action,
        ?string $comment,
        array $people,
        ?int $recordRevisionId = null,
    ): WorkflowCaseHistory {
        $caseId = $permit->case()->id;
        $history = WorkflowCaseHistory::query()->create([
            'workflow_case_id' => $caseId,
            'workflow_case_node_id' => $permit->node()->id,
            'action' => $action,
            'user_id' => $actor->processorUserId,
            'executor_user_id' => $actor->recordedExecutorUserId(),
            'comment' => $comment,
            'record_revision_id' => $recordRevisionId,
            'node_changes' => [],
        ]);

        foreach ($people as $index => $userId) {
            $row = ['workflow_case_id' => $caseId, 'workflow_case_history_id' => $history->id];
            WorkflowCaseHistoryUser::query()->create([...$row, 'sequence' => $index + 1, 'user_id' => $userId]);
        }

        return $history;
    }
}

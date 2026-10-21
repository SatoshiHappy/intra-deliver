<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowConfirmationProgress;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseNode;
use LogicException;

/**
 * 確認の開きと確認の状態の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.5 節、
 * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.11 節)。確認の業務処理・状況の役割(確認対象者)・詳細の確認の面が同じ規則を使う。鍵を取らない。
 */
class CaseConfirmations
{
    /**
     * 案件ごとの、その人が確認対象者の開いている確認のノード(未確認・確認済の両方)。問い合わせは案件の数に依らず 1 回。
     *
     * @param  list<int>  $caseIds
     * @return array<int, list<int>> 案件の id → ノードの id(昇順。無い案件は入れない)
     */
    public function openTargetNodeIds(array $caseIds, int $userId): array
    {
        if ($caseIds === []) {
            return [];
        }

        $rows = WorkflowCaseConfirmTarget::query()->from('workflow_case_confirm_targets as targets')
            ->join('workflow_case_nodes as nodes', 'nodes.id', '=', 'targets.workflow_case_node_id')
            ->join('workflow_cases as cases', 'cases.id', '=', 'targets.workflow_case_id')
            ->whereIn('targets.workflow_case_id', $caseIds)
            ->where('targets.user_id', $userId)
            ->where('nodes.state', WorkflowNodeState::Done->value)
            ->whereNotNull('nodes.confirm_authz_subject_group_id')
            ->where('cases.state', WorkflowCaseState::Active->value)
            ->orderBy('targets.workflow_case_id')
            ->orderBy('targets.workflow_case_node_id')
            ->toBase()
            ->get(['targets.workflow_case_id as case_id', 'targets.workflow_case_node_id as node_id']);
        $byCase = [];

        foreach ($rows as $row) {
            $byCase[(int) $row->case_id][] = (int) $row->node_id;
        }

        return $byCase;
    }

    /**
     * 案件の確認対象者ごとの確認の状態(確認対象者の行を持つノードだけ。確認が初めて開くまでは行が無い = 表.md の 3.10 節)。
     *
     * @return array<int, array<int, WorkflowConfirmationProgress>> ノードの id → 確認対象者のユーザーの id → 状態(どちらも昇順)
     *
     * @throws LogicException 確認対象者の行が確認する人を持たないノードを指すとき(写しの列は変えないので起きない)
     */
    public function statesOf(WorkflowCase $case): array
    {
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)
            ->whereNotNull('confirm_authz_subject_group_id')->get()->keyBy('id');
        $targets = WorkflowCaseConfirmTarget::query()->where('workflow_case_id', $case->id)
            ->orderBy('workflow_case_node_id')->orderBy('user_id')->get();
        $confirmed = [];

        foreach (WorkflowCaseConfirmation::query()->where('workflow_case_id', $case->id)->get() as $row) {
            $confirmed[self::key($row->workflow_case_node_id, $row->workflow_case_history_id, $row->user_id)] = true;
        }

        $states = [];

        foreach ($targets as $target) {
            $node = $nodes->get($target->workflow_case_node_id)
                ?? throw new LogicException("確認対象者の行のノード {$target->workflow_case_node_id} に確認する人が無い");
            $isConfirmed = isset($confirmed[self::key($node->id, $node->workflow_case_history_id, $target->user_id)]);
            $states[$node->id][$target->user_id] = self::stateOf($case, $node, $isConfirmed);
        }

        return $states;
    }

    /**
     * 1 人の確認の状態(設計/ワークフロー/案件の運用/案件の操作/README.md の 1.5 節の式)。
     *
     * @param  bool  $confirmed  その人の確認の行のうち、ノードの今の履歴の行を指すものがあるか
     */
    public static function stateOf(
        WorkflowCase $case,
        WorkflowCaseNode $node,
        bool $confirmed,
    ): WorkflowConfirmationProgress {
        $done = $node->state === WorkflowNodeState::Done;

        return match (true) {
            $node->state === WorkflowNodeState::Skipped => WorkflowConfirmationProgress::Skipped,
            $case->state === WorkflowCaseState::Completed && ! $done => WorkflowConfirmationProgress::NotProcessed,
            $done && $confirmed => WorkflowConfirmationProgress::Confirmed,
            $done => WorkflowConfirmationProgress::Unconfirmed,
            $node->kind === WorkflowNodeKind::Apply => WorkflowConfirmationProgress::Reapply,
            default => WorkflowConfirmationProgress::Waiting,
        };
    }

    private static function key(int $nodeId, ?int $historyId, int $userId): string
    {
        return "{$nodeId}:{$historyId}:{$userId}";
    }
}

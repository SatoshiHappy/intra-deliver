<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\SubjectGroupMembership;
use LogicException;

/**
 * 計画を書いた後に確認を開く 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 9 節、README.md の 1.5 節、
 * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.10 節)。確認対象者の行は初めて開いた時だけ申請基準日で展開して書き、開き直しでは書き直さない。
 * 呼び手のトランザクションの中で呼ぶ。
 */
class CaseConfirmOpening
{
    public function __construct(private SubjectGroupMembership $membership) {}

    /**
     * この操作の履歴の行で処理済みになり、確認する人を持つノードの確認を開く(案件が操作の後も未完了のときだけ)。
     *
     * @return array<int, list<int>> 確認が開いたノードの id(昇順)→ 確認対象者(昇順。0 人のノードは確認が無いので入れない)
     */
    public function open(CaseRoutePlan $plan): array
    {
        $nodeIds = $plan->completion === null ? $plan->newlyProcessedNodeIds() : [];

        if ($nodeIds === []) {
            return [];
        }

        $nodes = WorkflowCaseNode::query()->whereIn('id', $nodeIds)
            ->whereNotNull('confirm_authz_subject_group_id')->orderBy('id')->get();
        $opened = [];

        foreach ($nodes as $node) {
            $targets = $this->targetsOf($plan, $node);

            if ($targets !== []) {
                $opened[$node->id] = $targets;
            }
        }

        return $opened;
    }

    /**
     * 確認対象者(行があればそのまま、無ければ申請基準日で展開して書く。0 人なら書かない = 次に開いた時に展開し直す)。
     *
     * @return list<int>
     *
     * @throws LogicException 案件に申請基準日が無いとき(申請した案件の不変の条件の破れ)
     */
    private function targetsOf(CaseRoutePlan $plan, WorkflowCaseNode $node): array
    {
        $existing = WorkflowCaseConfirmTarget::query()->where('workflow_case_node_id', $node->id)
            ->orderBy('user_id')->pluck('user_id')->all();

        if ($existing !== []) {
            return array_values(array_map('intval', $existing));
        }

        $case = $plan->case;
        $baseDate = $case->base_date ?? throw new LogicException("案件 {$case->id} に申請基準日が無い");
        $userIds = $this->membership->memberUserIdsOn((int) $node->confirm_authz_subject_group_id, $baseDate);
        $rows = array_map(fn (int $userId) => [
            'workflow_case_id' => $case->id, 'workflow_case_node_id' => $node->id, 'user_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ], $userIds);
        WorkflowCaseConfirmTarget::query()->insert($rows);

        return $userIds;
    }
}

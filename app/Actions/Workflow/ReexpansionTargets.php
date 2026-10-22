<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\CurrentAssignments;
use App\Queries\Workflow\NodeTargetExpansion;
use App\Queries\Workflow\OtherChildPeople;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 再展開の人(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.4 節・15.1 節の 1)。鍵を取らない(案件の行の鍵の下で呼ぶ)。
 */
class ReexpansionTargets
{
    public function __construct(
        private NodeTargetExpansion $expansion,
        private CurrentAssignments $assignments,
        private ApplicantApprovalGuard $applicantGuard,
        private OtherChildPeople $otherChildPeople,
    ) {}

    /**
     * @return list<int> 展開し直した人(1 人以上)
     *
     * @throws ValidationException 0 人・申請者だけ・ほかの子の人と重なるとき(case)
     */
    public function of(WorkflowCase $case, WorkflowCaseNode $node): array
    {
        $baseDate = $case->base_date ?? throw new LogicException("未完了の案件 {$case->id} に申請基準日が無い");
        $userIds = $this->expanded($case, $node, $baseDate);

        if ($userIds === []) {
            throw CaseOperationErrors::noTargets($node->displayName());
        }

        $applicantIds = $node->prevents_applicant_approval ? $this->applicantGuard->applicantUserIds($case) : [];

        if ($this->applicantGuard->onlyApplicants($node->prevents_applicant_approval, $userIds, $applicantIds)) {
            throw CaseOperationErrors::applicantOnlyTargets($node->displayName(), $userIds, $case->user_id);
        }

        if (array_intersect($userIds, $this->otherChildPeople->of($node)) !== []) {
            throw CaseOperationErrors::ofCase(TransferRules::OTHER_CHILD);
        }

        return $userIds;
    }

    /**
     * 展開の 1 か所({@see NodeTargetExpansion})か今の指定で、人を求める(指定の人に申請基準日に無効な人がいれば 0 人と同じに扱う = 前のノードからの
     * 到達と同じ規則)。
     *
     * @return list<int>
     */
    private function expanded(WorkflowCase $case, WorkflowCaseNode $node, DateTimeInterface $baseDate): array
    {
        $parentId = $node->parent_workflow_case_node_id;

        if ($parentId === null && $node->target_kind !== WorkflowTargetKind::Designated) {
            $origin = $this->expansion->recordedOriginOf($case, $node, $baseDate);

            return $this->expansion->expand($node, $case->department_id, $origin, $baseDate);
        }

        $assigned = $this->assignments->of($case->id)[$parentId ?? $node->id] ?? [];
        $userIds = $parentId === null ? $assigned : array_slice($assigned, (int) $node->sequence - 1, 1);

        return $this->expansion->allEffectiveOn($userIds, $baseDate) ? $userIds : [];
    }
}

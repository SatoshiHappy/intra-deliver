<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\OtherChildPeople;
use App\Queries\Workflow\ReexpandedTargets;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 再展開の人(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.4 節・15.1 節の 1)。鍵を取らない(案件の行の鍵の下で呼ぶ)。
 * 展開し直す人は詳細の申請操作の面と同じ {@see ReexpandedTargets} で求め、ここは拒否を足す。
 */
class ReexpansionTargets
{
    public function __construct(
        private ReexpandedTargets $reexpanded,
        private ApplicantApprovalGuard $applicantGuard,
        private OtherChildPeople $otherChildPeople,
    ) {}

    /**
     * @return list<int> 展開し直した人(1 人以上)
     *
     * @throws ValidationException 0 人・申請者だけ・ほかの子の人と重なるとき(case)
     * @throws LogicException 案件が申請基準日を持たないとき
     */
    public function of(WorkflowCase $case, WorkflowCaseNode $node): array
    {
        $userIds = $this->reexpanded->of($case, $node);

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
}

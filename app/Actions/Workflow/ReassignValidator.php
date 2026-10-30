<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\ReassignCandidates;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 指定の変更の前提と人の規則(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.6 節、文言 = 同時実行と検査.md の 13 節)。
 * 前提は指定されるノード Y が現在のノードでないので表の外で見る(15.2 節の行の前提。外れれば 422 case。式は詳細の申請操作の面と同じ
 * {@see ReassignCandidates})。鍵を取らない(案件の行の鍵の下で呼ぶ)。
 */
class ReassignValidator
{
    /** 入力のキー */
    public const KEY = 'user_ids';

    /** %1$s = ステップの名前、%2$d = 最小、%3$d = 最大 */
    public const COUNT = '%1$sの処理担当者は %2$d 人から %3$d 人まで指定してください';

    /** 人数の下限 = 上限のとき。%1$s = ステップの名前、%2$d = 人数 */
    public const EXACT_COUNT = '%1$sの処理担当者は %2$d 人を指定してください';

    public function __construct(
        private EffectiveUsers $effectiveUsers,
        private ApplicantApprovalGuard $applicantGuard,
        private ReassignCandidates $reassignCandidates,
    ) {}

    /**
     * 指定の変更の前提(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.2 節の行の前提)。
     *
     * @throws ValidationException 前提に外れたとき(case)
     */
    public function requireReassignable(WorkflowCaseNode $node): void
    {
        if (! $this->reassignCandidates->isReassignable($node)) {
            throw CaseOperationErrors::ofCase(CaseOperationErrors::NOT_PERMITTED);
        }
    }

    /**
     * @param  list<int>  $userIds  指定する人(入力 user_ids の並び)
     * @return list<int> 確かめた人の並び
     *
     * @throws ValidationException 規則に外れたとき(user_ids)
     */
    public function validated(WorkflowCase $case, WorkflowCaseNode $node, CaseActor $actor, array $userIds): array
    {
        $message = $this->messageOf($case, $node, $actor, $userIds);

        if ($message !== null) {
            throw ValidationException::withMessages([self::KEY => [$message]]);
        }

        return $userIds;
    }

    /**
     * 規則 (1)〜(5) の順で最初に外れたものの文言(無ければ null)。
     *
     * @param  list<int>  $userIds
     */
    private function messageOf(WorkflowCase $case, WorkflowCaseNode $node, CaseActor $actor, array $userIds): ?string
    {
        $baseDate = $case->base_date ?? throw new LogicException("未完了の案件 {$case->id} に申請基準日が無い");
        $max = $node->max_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い");
        $min = $node->min_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い");
        $count = count($userIds);
        $unique = array_values(array_unique($userIds));
        $prevents = $node->prevents_applicant_approval;
        $applicantIds = fn () => $this->applicantGuard->applicantUserIds($case);
        $allEffective = fn () => count($this->effectiveUsers->idsOn($unique, $baseDate)) === count($unique);

        return match (true) {
            $count < $min || $count > $max => $min === $max
                ? sprintf(self::EXACT_COUNT, $node->displayName(), $min)
                : sprintf(self::COUNT, $node->displayName(), $min, $max),
            ! $allEffective() => CaseOperationErrors::ASSIGNMENTS_NOT_EFFECTIVE,
            count($unique) !== $count => CaseOperationErrors::ASSIGNMENTS_DUPLICATE,
            $prevents && in_array($case->user_id, $userIds, true) => TransferRules::APPLICANT,
            $prevents && array_intersect($userIds, $applicantIds()) !== [] => TransferRules::APPLICANT_PROXY,
            in_array($actor->processorUserId, $userIds, true) => RetargetValidator::SELF,
            default => null,
        };
    }
}

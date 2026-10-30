<?php

namespace App\Actions\Workflow;

use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\OtherChildPeople;
use App\Queries\Workflow\RetargetUserLimit;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 処理対象者の変更の変更後の人の規則(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.3 節。振替先の規則と同じ形、
 * 文言 = 同時実行と検査.md の 13 節)。鍵を取らない(案件の行の鍵の下で呼ぶ)。
 */
class RetargetValidator
{
    /** 入力のキー */
    public const KEY = 'user_ids';

    public const COUNT = '処理担当者を 1 人から '.RetargetUserLimit::MAX_USERS.' 人まで指定してください';

    public const ONE_FOR_CHILD = 'このステップの処理担当者は 1 人を指定してください';

    public const SELF = '自分自身は処理担当者に指定できません';

    /** 存在しない・申請基準日に有効でない(処理の時の指定と同じ文) */
    public const NOT_EFFECTIVE = CaseOperationErrors::ASSIGNMENTS_NOT_EFFECTIVE;

    public function __construct(
        private EffectiveUsers $effectiveUsers,
        private ApplicantApprovalGuard $applicantGuard,
        private OtherChildPeople $otherChildPeople,
    ) {}

    /**
     * @param  list<int>  $userIds  変更後の人(入力 user_ids の並び)
     * @return list<int> 確かめた変更後の人(入力の並び。1 人以上)
     *
     * @throws ValidationException 規則に外れたとき(user_ids)
     */
    public function validated(CaseOperationPermit $permit, CaseActor $actor, array $userIds): array
    {
        $message = $this->messageOf($permit, $actor, $userIds);

        if ($message !== null) {
            throw ValidationException::withMessages([self::KEY => [$message]]);
        }

        return $userIds;
    }

    /**
     * 人数と規則 (1)〜(5) の順で最初に外れたものの文言(無ければ null)。
     *
     * @param  list<int>  $userIds
     */
    private function messageOf(CaseOperationPermit $permit, CaseActor $actor, array $userIds): ?string
    {
        [$case, $node] = [$permit->case(), $permit->node()];
        $baseDate = $case->base_date ?? throw new LogicException("未完了の案件 {$case->id} に申請基準日が無い");
        $count = count($userIds);
        $parentId = $node->parent_workflow_case_node_id;
        $isChild = RetargetUserLimit::isChild($parentId);
        $prevents = $node->prevents_applicant_approval;
        $applicantIds = fn () => $this->applicantGuard->applicantUserIds($case);
        $unique = array_values(array_unique($userIds));

        return match (true) {
            $isChild && $count !== 1 => self::ONE_FOR_CHILD,
            $count < 1 || $count > RetargetUserLimit::maxOf($parentId) => self::COUNT,
            count($this->effectiveUsers->idsOn($unique, $baseDate)) !== count($unique) => self::NOT_EFFECTIVE,
            count($unique) !== $count => CaseOperationErrors::ASSIGNMENTS_DUPLICATE,
            in_array($actor->processorUserId, $userIds, true) => self::SELF,
            array_intersect($userIds, $this->otherChildPeople->of($node)) !== [] => TransferRules::OTHER_CHILD,
            $prevents && in_array($case->user_id, $userIds, true) => TransferRules::APPLICANT,
            $prevents && array_intersect($userIds, $applicantIds()) !== [] => TransferRules::APPLICANT_PROXY,
            default => null,
        };
    }
}

<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\OtherChildPeople;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 振替先の規則(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節、文言 = 同時実行と検査.md の 13 節)。鍵を取らない(案件の行の鍵の下で呼ぶ)。
 */
class TransferRules
{
    /** 入力のキー */
    public const KEY = 'to_user_id';

    public const REQUIRED = '担当変更先を選択してください';

    /** 存在しない・申請基準日に有効でない(処理の時の指定と同じ文) */
    public const NOT_EFFECTIVE = CaseOperationErrors::ASSIGNMENTS_NOT_EFFECTIVE;

    public const SELF_OR_PRINCIPAL = '自分自身と代理元は担当変更先に指定できません';

    public const ALREADY_TARGET = 'このユーザーは、すでにこのステップの処理担当者です';

    public const OTHER_CHILD = 'このステップのほかの承認者と同じユーザーは指定できません';

    public const APPLICANT = '申請者本人は、このステップの処理担当者に指定できません';

    /** 今日の申請者の代理先(指定と同じ文) */
    public const APPLICANT_PROXY = CaseOperationErrors::ASSIGNMENTS_APPLICANT_PROXY;

    public function __construct(
        private EffectiveUsers $effectiveUsers,
        private ApplicantApprovalGuard $applicantGuard,
        private OtherChildPeople $otherChildPeople,
    ) {}

    /**
     * @return int 確かめた振替先
     *
     * @throws ValidationException 規則に外れたとき(to_user_id)
     */
    public function validated(CaseOperationPermit $permit, CaseActor $actor, ?int $toUserId): int
    {
        if ($toUserId === null) {
            throw self::error(self::REQUIRED);
        }

        $message = $this->messageOf($permit, $actor, $toUserId);

        if ($message !== null) {
            throw self::error($message);
        }

        return $toUserId;
    }

    private static function error(string $message): ValidationException
    {
        return ValidationException::withMessages([self::KEY => [$message]]);
    }

    /**
     * 規則の順(10.3 節)で最初に外れたものの文言(無ければ null)。
     */
    private function messageOf(CaseOperationPermit $permit, CaseActor $actor, int $toUserId): ?string
    {
        [$case, $node] = [$permit->case(), $permit->node()];
        $baseDate = $case->base_date ?? throw new LogicException("未完了の案件 {$case->id} に申請基準日が無い");
        $prevents = $node->prevents_applicant_approval;
        $applicantIds = fn () => $this->applicantGuard->applicantUserIds($case);

        return match (true) {
            $this->effectiveUsers->idsOn([$toUserId], $baseDate) === [] => self::NOT_EFFECTIVE,
            in_array($toUserId, [$actor->processorUserId, $actor->executorUserId], true) => self::SELF_OR_PRINCIPAL,
            in_array($toUserId, self::currentTargetsOf($permit, $node), true) => self::ALREADY_TARGET,
            in_array($toUserId, $this->otherChildPeople->of($node), true) => self::OTHER_CHILD,
            $prevents && $toUserId === $case->user_id => self::APPLICANT,
            $prevents && in_array($toUserId, $applicantIds(), true) => self::APPLICANT_PROXY,
            default => null,
        };
    }

    /**
     * 鍵の下で読んだ状況の、操作するノードの処理対象者(状況を判定の 1 か所にし、DB を読み直さない)。
     *
     * @return list<int>
     *
     * @throws LogicException 操作するノードが現在のノードでないとき(表を通った不変の条件の破れ)
     */
    private static function currentTargetsOf(CaseOperationPermit $permit, WorkflowCaseNode $node): array
    {
        $current = $permit->situation->currentNode($node->id)
            ?? throw new LogicException("振替するノード {$node->id} が現在のノードでない");

        return $current->targetUserIds;
    }
}

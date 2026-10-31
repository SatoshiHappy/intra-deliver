<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\ApplicantApprovalTerm;
use App\Models\Workflow\WorkflowCase;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 申請者承認防止の判定の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 6.1・6.2、
 * 設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節)と、「申請者として扱う人」の 1 か所(処理対象者.md 4.6・5.2 の 5)。
 * 到達・指定・振替・候補・先読みが同じ人を除くよう、ここだけで引く。ノードの旗と操作の種別は呼び手が見る。
 */
class ApplicantApprovalGuard
{
    /** 拒むときの文言(業務処理の 422 case と詳細の blockedReason = 画面/案件の側の画面.md 6 節。ルートの深さ/案件の操作/同時実行と検査.md 13 節) */
    public const MESSAGE = '申請者本人は、この申請を承認できません。このステップには、申請者承認防止が設定されています。';

    /** 申請者の代理先が本人として承認・承認終了・否認するときの文言(設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const PROXY_MESSAGE = '申請者の代理先に設定されているため、この申請を承認できません。このステップには、申請者承認防止が設定されています。';

    /** 申請者が保留するときの文言(設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const HOLD_MESSAGE = '申請者本人は、この申請を保留できません。このステップには、申請者承認防止が設定されています。';

    /** 申請者の代理先が本人として保留するときの文言(同じ 13 節) */
    public const HOLD_PROXY_MESSAGE = '申請者の代理先に設定されているため、この申請を保留できません。'
        .'このステップには、申請者承認防止が設定されています。';

    /**
     * 展開の結果が申請者として扱う人だけのときの文言の書式(%s = ノードの表示名。業務処理の 422 case と申請の画面の next.nodes の blockedReason = 13 節・画面/申請と案件.md 5 節と、詳細・一覧の行の pullBackNodes の blockedReason = {@see CaseOperationDecider::situationsOf()}・画面/申請と案件.md 6 節)
     */
    public const ONLY_APPLICANT_TARGETS = '%sの処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。';

    /** 展開の結果が申請者と申請者の代理先だけ(代理先が 1 人以上)のときの文言の書式(%s = ノードの表示名。同じ 13 節) */
    public const ONLY_APPLICANT_OR_PROXY_TARGETS = '%sの処理担当者が申請者本人と申請者の代理先だけのため、この操作はできません。'
        .'このステップには、申請者承認防止が設定されています。';

    public function __construct(private WorkflowProxies $proxies) {}

    /**
     * 操作する人が申請者承認防止で拒まれる人なら、当たった項(当たらなければ null)。
     */
    public function rejectingTerm(CaseActor $actor, WorkflowCase $case): ?ApplicantApprovalTerm
    {
        return self::termOf($actor, $case->user_id, $this->applicantUserIds($case));
    }

    /**
     * 当たった項の判定の本体(DB を読まない。項の順 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節の表)。
     *
     * @param  list<int>  $applicantUserIds  申請者として扱う人({@see self::applicantUserIds()})
     */
    public static function termOf(
        CaseActor $actor,
        int $applicantUserId,
        array $applicantUserIds,
    ): ?ApplicantApprovalTerm {
        $executor = $actor->executorUserId;

        return match (true) {
            $actor->processorUserId === $applicantUserId => ApplicantApprovalTerm::ProcessorIsApplicant,
            $executor === $applicantUserId => ApplicantApprovalTerm::ExecutorIsApplicant,
            in_array($executor, $applicantUserIds, true) => ApplicantApprovalTerm::ExecutorIsApplicantProxy,
            default => null,
        };
    }

    /**
     * 承認・承認終了・否認を拒むときの文言(当たった項で選ぶ)。
     */
    public static function approvalMessage(ApplicantApprovalTerm $term): string
    {
        return $term->isApplicantHimself() ? self::MESSAGE : self::PROXY_MESSAGE;
    }

    /**
     * 保留を拒むときの文言(当たった項で選ぶ。承認・承認終了・否認は {@see self::approvalMessage()})。
     */
    public static function holdMessage(ApplicantApprovalTerm $term): string
    {
        return $term->isApplicantHimself() ? self::HOLD_MESSAGE : self::HOLD_PROXY_MESSAGE;
    }

    /**
     * 案件の申請者として扱う人({@see self::applicantUserIdsByCase()} の 1 件)。
     *
     * @return non-empty-list<int> ユーザーの id
     */
    public function applicantUserIds(WorkflowCase $case): array
    {
        return $this->applicantUserIdsByCase([$case])[$case->id];
    }

    /**
     * 案件ごとの申請者として扱う人(申請者を先頭に、ほかは昇順)。代理の表の問い合わせは案件の数に依らず 1 回。
     *
     * @param  list<WorkflowCase>  $cases
     * @return array<int, non-empty-list<int>> 案件の id → ユーザーの id
     */
    public function applicantUserIdsByCase(array $cases): array
    {
        $caseIds = array_map(fn (WorkflowCase $case) => $case->id, $cases);
        $proxies = $this->proxies->applicantProxyIdsOf($caseIds, self::today());
        $byCase = [];

        foreach ($cases as $case) {
            $byCase[$case->id] = self::applicantFirst($case->user_id, $proxies[$case->id] ?? []);
        }

        return $byCase;
    }

    /**
     * 案件の無い申請の画面の申請者として扱う人(申請者とフローから引く)。
     *
     * @return non-empty-list<int> ユーザーの id
     */
    public function applicantUserIdsOf(int $applicantUserId, int $flowId): array
    {
        $proxies = $this->proxies->proxyIdsOf($applicantUserId, $flowId, self::today());

        return self::applicantFirst($applicantUserId, $proxies);
    }

    /**
     * 防止がオンのノードの展開の結果が、申請者として扱う人だけか(処理対象者.md 4.6。0 人は別の規則)。
     *
     * @param  list<int>  $targetUserIds  展開の結果
     * @param  list<int>  $applicantUserIds  申請者として扱う人
     */
    public function onlyApplicants(bool $prevents, array $targetUserIds, array $applicantUserIds): bool
    {
        return $prevents && $targetUserIds !== [] && array_diff($targetUserIds, $applicantUserIds) === [];
    }

    /**
     * 申請者を先頭に、ほかは代理先の並び(昇順)のまま重ねない。
     *
     * @param  list<int>  $proxyUserIds
     * @return non-empty-list<int>
     */
    private static function applicantFirst(int $applicantUserId, array $proxyUserIds): array
    {
        return [$applicantUserId, ...array_values(array_diff($proxyUserIds, [$applicantUserId]))];
    }

    /**
     * 代理の項の今日(時計から読む。入力の申請基準日は使わない = 設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節)。
     */
    private static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE);
    }

    /**
     * 展開の結果が申請者として扱う人だけのときの文言(除いた人に申請者の代理先がいれば代理先を含む文、申請者だけなら申請者本人の文)。
     *
     * @param  list<int>  $targetUserIds  展開の結果
     */
    public static function onlyApplicantTargetsMessage(
        string $nodeName,
        array $targetUserIds,
        int $applicantUserId,
    ): string {
        $hasProxy = array_diff($targetUserIds, [$applicantUserId]) !== [];

        return sprintf($hasProxy ? self::ONLY_APPLICANT_OR_PROXY_TARGETS : self::ONLY_APPLICANT_TARGETS, $nodeName);
    }
}

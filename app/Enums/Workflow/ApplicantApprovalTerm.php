<?php

namespace App\Enums\Workflow;

/**
 * 申請者承認防止で拒むときに当たった項(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 5 節)。
 * 文言を選ぶのに使う(処理者か実行者が申請者なら申請者本人の文、ほかは申請者の代理先の文
 * = 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節)。
 */
enum ApplicantApprovalTerm
{
    /** 処理者 = 申請者 */
    case ProcessorIsApplicant;

    /** 実行者 = 申請者 */
    case ExecutorIsApplicant;

    /** 実行者が、今日の申請者の代理先 */
    case ExecutorIsApplicantProxy;

    /**
     * 申請者本人に当たった項か(申請者本人の文を出す)。
     */
    public function isApplicantHimself(): bool
    {
        return $this !== self::ExecutorIsApplicantProxy;
    }
}

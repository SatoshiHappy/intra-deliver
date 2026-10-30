<?php

namespace App\Enums\Workflow;

/**
 * 一覧の行に呼び手が求める追加の項目(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節の列、画面/README.md の 1.2 節の型)。
 * 求めない項目は空の値(null・空の並び・偽)にし、その項目のための問い合わせをしない。DB の列の値ではない。
 */
enum WorkflowCaseRowField
{
    /** 経過日数・期限(未処理の集まり = 案件の操作/README.md の 1.4 節) */
    case Deadline;

    /** 経過日数・期限(現在のノードの全部 = 申請操作の一覧。案件の運用/認可と一覧.md の 2.3 節) */
    case CurrentDeadline;

    /** 代理元(未処理の集まりのうち、自分が本人として当たらないノードの代理元) */
    case Principals;

    /** 一括承認の組と can.bulkApprove */
    case BulkApproval;

    /** 確認するステップと確認が開いた日時(最も古いもの)、can.confirm */
    case OpenConfirmation;

    /** 確認したステップと確認日時(最新のもの) */
    case DoneConfirmation;
}

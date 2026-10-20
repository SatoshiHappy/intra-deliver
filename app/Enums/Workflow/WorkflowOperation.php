<?php

namespace App\Enums\Workflow;

/**
 * 案件の操作(docs の 設計/ワークフロー/案件の操作/操作.md 3.2 と 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節の状態 × 操作の表の行)。
 * 判定の表(読み取りの CaseOperationTable)の引数。DB の列の値ではない(検査制約は無い)。
 */
enum WorkflowOperation: string
{
    /** 一時保存(新規。案件が無い) */
    case SaveDraft = 'save_draft';

    /** 一時保存(上書き) */
    case OverwriteDraft = 'overwrite_draft';

    /** 一時保存の削除 */
    case DeleteDraft = 'delete_draft';

    /** 申請(新規・一時保存からの両方) */
    case Apply = 'apply';

    /** 承認 */
    case Approve = 'approve';

    /** 承認終了 */
    case ApproveEarly = 'approve_early';

    /** 否認 */
    case Reject = 'reject';

    /** 差戻し */
    case SendBack = 'send_back';

    /** 引戻し(差戻しの引戻しを含む) */
    case PullBack = 'pull_back';

    /** 再申請 */
    case Reapply = 'reapply';

    /** 取止め */
    case Withdraw = 'withdraw';

    /** 保留 */
    case Hold = 'hold';

    /** 保留解除 */
    case Release = 'release';

    /** 振替(画面の語は担当変更) */
    case Transfer = 'transfer';
}

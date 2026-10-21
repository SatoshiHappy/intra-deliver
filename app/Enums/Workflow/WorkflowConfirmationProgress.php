<?php

namespace App\Enums\Workflow;

/**
 * 確認対象者 1 人ごとの確認の状態(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.5 節)。DB の列の値ではない。
 * 決めるのは読み取りの CaseConfirmations の 1 か所。
 */
enum WorkflowConfirmationProgress: string
{
    /** 省略(ノードが省略) */
    case Skipped = 'skipped';

    /** —(到達しないまま完了した) */
    case NotProcessed = 'not_processed';

    /** 確認済 */
    case Confirmed = 'confirmed';

    /** 未確認 */
    case Unconfirmed = 'unconfirmed';

    /** 再申請待ち(申請のノードが処理済みでない) */
    case Reapply = 'reapply';

    /** 承認待ち(保留中を含む) */
    case Waiting = 'waiting';
}

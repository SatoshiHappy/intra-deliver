<?php

namespace App\Enums\Workflow;

/**
 * 完了の理由(docs の 設計/ワークフロー/データモデル/README.md 1 節。表 workflow_cases の completion_reason。完了の案件だけが持つ)。
 * 値は DB の検査制約と同じ一覧。
 */
enum WorkflowCompletionReason: string
{
    /** 承認(終了に到達) */
    case Approved = 'approved';

    /** 承認終了(途中の承認で終える) */
    case ApprovedEarly = 'approved_early';

    /** 否認 */
    case Rejected = 'rejected';

    /** 取止め */
    case Withdrawn = 'withdrawn';
}

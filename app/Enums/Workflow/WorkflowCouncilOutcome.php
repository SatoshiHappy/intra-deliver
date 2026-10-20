<?php

namespace App\Enums\Workflow;

/**
 * 縦配置の合議の判定の結果(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.5)。DB の列の値ではない。
 */
enum WorkflowCouncilOutcome: string
{
    /** 可決(承認の数 ≥ 必要な数) */
    case Passed = 'passed';

    /** 可決できなくなった(人数 − 否認の数 < 必要な数) */
    case Failed = 'failed';

    /** 未決(残りの子の処理を待つ) */
    case Pending = 'pending';
}

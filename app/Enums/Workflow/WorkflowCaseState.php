<?php

namespace App\Enums\Workflow;

/**
 * 案件の状態(docs の 設計/ワークフロー/データモデル/README.md 1 節。表 workflow_cases の state)。値は DB の検査制約と同じ一覧。
 * 遷移は Draft → Active → Completed だけ(戻らない = データモデル/表.md 2.5)。
 */
enum WorkflowCaseState: string
{
    /** 一時保存(案件番号・案件名・レコードを持たず、一時保存の入力を持つ) */
    case Draft = 'draft';

    /** 未完了(現在のノードを 1 つ以上持つ) */
    case Active = 'active';

    /** 完了(完了の理由と完了の日時を持つ) */
    case Completed = 'completed';
}

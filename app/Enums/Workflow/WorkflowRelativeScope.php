<?php

namespace App\Enums\Workflow;

/**
 * 起点で指定の組織の範囲(表 workflow_route_nodes・workflow_case_nodes の relative_scope)。docs の 設計/ワークフロー/ルートの深さ/データモデル/README.md 1 節。値は DB の検査制約と同じ一覧。
 */
enum WorkflowRelativeScope: string
{
    /** 所属組織 */
    case Own = 'own';

    /** 上位組織(1 段上) */
    case Parent = 'parent';

    /** 上位組織全て(根まで) */
    case Ancestors = 'ancestors';
}

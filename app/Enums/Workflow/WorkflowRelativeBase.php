<?php

namespace App\Enums\Workflow;

/**
 * 起点で指定の起点(表 workflow_route_nodes・workflow_case_nodes の relative_base)。docs の 設計/ワークフロー/ルートの深さ/データモデル/README.md 1 節。値は DB の検査制約と同じ一覧。
 */
enum WorkflowRelativeBase: string
{
    /** 申請者 */
    case Applicant = 'applicant';

    /** 前処理者 */
    case Previous = 'previous';
}

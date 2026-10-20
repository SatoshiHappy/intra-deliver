<?php

namespace App\Enums\Workflow;

/**
 * 処理対象者の指定の種類(表 workflow_route_nodes・workflow_case_nodes の target_kind。申請・承認・横配置・縦配置のノードだけが持つ)。docs の 設計/ワークフロー/ルートの深さ/データモデル/README.md 1 節。値は DB の検査制約と同じ一覧。
 */
enum WorkflowTargetKind: string
{
    /** 対象者で指定(認可の対象者を指す) */
    case Fixed = 'fixed';

    /** 起点で指定(申請者・前処理者の組織と役職) */
    case Relative = 'relative';

    /** 処理の時に指定(指定するノードの処理者が指定する) */
    case Designated = 'designated';
}

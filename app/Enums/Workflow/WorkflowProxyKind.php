<?php

namespace App\Enums\Workflow;

/**
 * 代理の別(docs の 設計/ワークフロー/案件の運用/データモデル/README.md の 1 節。表 workflow_proxies の kind)。値は DB の検査制約と同じ一覧。
 * 値はノードの種類の値と同じ語にする(効くノードの種類と同じ値)。
 */
enum WorkflowProxyKind: string
{
    /** 申請の代理 */
    case Apply = 'apply';

    /** 承認の代理 */
    case Approve = 'approve';
}

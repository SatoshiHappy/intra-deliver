<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCaseNode;

/**
 * 案件操作の、ほかのノードを指定するノードかの規則(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節・15.6 節):
 * 進むで拒み、指定の変更の前提で外す。業務処理(ステップの移動・指定の変更)と詳細の申請操作の面が同じ式を使う。鍵を取らない。
 */
class DesignatorNodeGuard
{
    /**
     * ほかのノードの指定するノードか(進むで拒み、指定の変更の Y の前提で外す)。
     */
    public function designatesAnother(int $nodeId): bool
    {
        return WorkflowCaseNode::query()->where('designator_workflow_case_node_id', $nodeId)->exists();
    }
}

<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 定義の管理の書き込みの入口の 404(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 6 節の判定の順): FormRequest の authorize が、
 * 封筒の検証より前に、フロー・そのフローの版・その版のノードが在るかを確かめる。業務処理は鍵の下で同じものをもう一度引く。鍵を取らない。
 */
class FlowDefinitionGate
{
    /**
     * @param  int|null  $versionNumber  版の番号(版を指さないルートは null)
     * @param  string|null  $nodeCode  ノードのコード(ノードを指さないルートは null)
     *
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowFlowVersion|WorkflowRouteNode> 無いとき
     */
    public function authorize(string $flowCode, ?int $versionNumber = null, ?string $nodeCode = null): void
    {
        $flowId = WorkflowFlow::query()->where('code', $flowCode)->firstOrFail()->id;

        if ($versionNumber === null) {
            return;
        }

        $versionId = WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->where('number', $versionNumber)
            ->firstOrFail()->id;

        if ($nodeCode !== null) {
            WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->where('code', $nodeCode)
                ->firstOrFail();
        }
    }
}

<?php

namespace App\Models\Workflow;

use Database\Factories\Workflow\WorkflowRouteSendBackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 差戻しできる先(ルートのノードの部品)。表 workflow_route_send_backs(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.5)。
 * 差戻し元のノードは、行のある先にだけ差戻しできる(行が無いノードでは差戻しできない)。元と先は同じ版のノード(複合外部キー)。版と一緒に消える。
 */
#[Fillable(['workflow_flow_version_id', 'workflow_route_node_id', 'target_workflow_route_node_id'])]
class WorkflowRouteSendBack extends Model
{
    /** @use HasFactory<WorkflowRouteSendBackFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WorkflowFlowVersion, $this>
     */
    public function workflowFlowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowFlowVersion::class);
    }

    /**
     * 差戻し元のノード。
     *
     * @return BelongsTo<WorkflowRouteNode, $this>
     */
    public function workflowRouteNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowRouteNode::class);
    }

    /**
     * 差戻しできる先のノード。
     *
     * @return BelongsTo<WorkflowRouteNode, $this>
     */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowRouteNode::class, 'target_workflow_route_node_id');
    }
}

<?php

namespace App\Models\Workflow;

use Database\Factories\Workflow\WorkflowRouteEdgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ルートの辺(版の部品)。表 workflow_route_edges(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.4)。
 * 両端は同じ版のノード(複合外部キー)。分岐開始から出る辺だけが名前と分岐の条件(業務データの入れ物の規則の条件の JSON)を持つ。
 */
#[Fillable(['workflow_flow_version_id', 'from_workflow_route_node_id', 'to_workflow_route_node_id', 'sort_key', 'label', 'condition'])]
class WorkflowRouteEdge extends Model
{
    /** @use HasFactory<WorkflowRouteEdgeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
            'condition' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WorkflowFlowVersion, $this>
     */
    public function workflowFlowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowFlowVersion::class);
    }

    /**
     * 出るノード。
     *
     * @return BelongsTo<WorkflowRouteNode, $this>
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowRouteNode::class, 'from_workflow_route_node_id');
    }

    /**
     * 入るノード。
     *
     * @return BelongsTo<WorkflowRouteNode, $this>
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowRouteNode::class, 'to_workflow_route_node_id');
    }
}

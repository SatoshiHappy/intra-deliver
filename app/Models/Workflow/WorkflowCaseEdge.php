<?php

namespace App\Models\Workflow;

use Database\Factories\Workflow\WorkflowCaseEdgeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 案件の辺(申請の時に版の辺を写した行。案件の部品)。表 workflow_case_edges(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.3)。
 * 写した後は taken(分岐開始の評価の結果。条件を持つ辺だけ)だけを変える。両端は同じ案件のノード(複合外部キー)。
 */
#[Fillable(['workflow_case_id', 'from_workflow_case_node_id', 'to_workflow_case_node_id', 'sort_key', 'label', 'condition', 'taken'])]
class WorkflowCaseEdge extends Model
{
    /** @use HasFactory<WorkflowCaseEdgeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
            'condition' => 'array',
            'taken' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkflowCase, $this>
     */
    public function workflowCase(): BelongsTo
    {
        return $this->belongsTo(WorkflowCase::class);
    }

    /**
     * 出るノード。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function fromNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class, 'from_workflow_case_node_id');
    }

    /**
     * 入るノード。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function toNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class, 'to_workflow_case_node_id');
    }
}

<?php

namespace App\Models\Workflow;

use Database\Factories\Workflow\WorkflowCaseSendBackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 写した差戻しできる先(案件のノードの部品)。表 workflow_case_send_backs(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.4)。
 * 元と先は同じ案件のノード(複合外部キー)。展開の行は行を持たない(親の行を読む)。
 */
#[Fillable(['workflow_case_id', 'workflow_case_node_id', 'target_workflow_case_node_id'])]
class WorkflowCaseSendBack extends Model
{
    /** @use HasFactory<WorkflowCaseSendBackFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WorkflowCase, $this>
     */
    public function workflowCase(): BelongsTo
    {
        return $this->belongsTo(WorkflowCase::class);
    }

    /**
     * 差戻し元のノード。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function workflowCaseNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class);
    }

    /**
     * 差戻しできる先のノード。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class, 'target_workflow_case_node_id');
    }
}

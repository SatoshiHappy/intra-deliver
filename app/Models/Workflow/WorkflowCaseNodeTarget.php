<?php

namespace App\Models\Workflow;

use App\Models\Master\User;
use Database\Factories\Workflow\WorkflowCaseNodeTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 処理対象者(誰が今そのノードを処理できるか。案件のノードの部品)。表 workflow_case_node_targets(docs の 設計/ワークフロー/データモデル/表.md 2.7)。
 * 現在のノードだけが行を持つ(業務処理の規則)。読み取りはいつもノードの状態と組で見る。
 */
#[Fillable(['workflow_case_node_id', 'user_id'])]
class WorkflowCaseNodeTarget extends Model
{
    /** @use HasFactory<WorkflowCaseNodeTargetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function workflowCaseNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

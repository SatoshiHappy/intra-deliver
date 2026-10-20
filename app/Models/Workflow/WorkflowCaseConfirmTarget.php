<?php

namespace App\Models\Workflow;

use App\Models\Master\User;
use Database\Factories\Workflow\WorkflowCaseConfirmTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 確認対象者(確認が初めて開いた時に、写した確認する人を申請基準日で展開した人。案件のノードの部品)。表 workflow_case_confirm_targets
 * (docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.10 節)。ノードは同じ案件のもの(複合外部キー)。
 */
#[Fillable(['workflow_case_id', 'workflow_case_node_id', 'user_id'])]
class WorkflowCaseConfirmTarget extends Model
{
    /** @use HasFactory<WorkflowCaseConfirmTargetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WorkflowCase, $this>
     */
    public function workflowCase(): BelongsTo
    {
        return $this->belongsTo(WorkflowCase::class);
    }

    /**
     * 確認するノード。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function workflowCaseNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class);
    }

    /**
     * 確認対象者。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

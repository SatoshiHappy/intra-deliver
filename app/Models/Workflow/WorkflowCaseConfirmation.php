<?php

namespace App\Models\Workflow;

use App\Models\Master\User;
use Database\Factories\Workflow\WorkflowCaseConfirmationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 確認(追記だけ。確認日時は created_at)。表 workflow_case_confirmations(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.11 節)。
 * ノードと履歴の行は同じ案件のもの、確認した人はそのノードの確認対象者(複合外部キー)。履歴の行は確認した時にそのノードを処理済みにしていた行。
 */
#[Fillable(['workflow_case_id', 'workflow_case_node_id', 'workflow_case_history_id', 'user_id'])]
class WorkflowCaseConfirmation extends Model
{
    /** @use HasFactory<WorkflowCaseConfirmationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<WorkflowCase, $this>
     */
    public function workflowCase(): BelongsTo
    {
        return $this->belongsTo(WorkflowCase::class);
    }

    /**
     * 確認したノード。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function workflowCaseNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class);
    }

    /**
     * 確認した時に、そのノードを処理済みにしていた履歴の行(どの開きへの確認か)。
     *
     * @return BelongsTo<WorkflowCaseHistory, $this>
     */
    public function workflowCaseHistory(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseHistory::class);
    }

    /**
     * 確認した人(本人だけ)。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

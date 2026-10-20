<?php

namespace App\Models\Workflow;

use App\Models\Master\User;
use Database\Factories\Workflow\WorkflowCaseAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 指定(指定するノードの処理者が承認の時に指定した処理対象者。案件の部品。追記だけ)。表 workflow_case_assignments
 * (docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.5)。指定した承認の履歴の行と指定されたノードは同じ案件のもの(複合外部キー)。
 */
#[Fillable(['workflow_case_id', 'workflow_case_history_id', 'workflow_case_node_id', 'sequence', 'user_id'])]
class WorkflowCaseAssignment extends Model
{
    /** @use HasFactory<WorkflowCaseAssignmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
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
     * 指定した承認の履歴の行。
     *
     * @return BelongsTo<WorkflowCaseHistory, $this>
     */
    public function workflowCaseHistory(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseHistory::class);
    }

    /**
     * 指定されたノード。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function workflowCaseNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class);
    }

    /**
     * 指定した人。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models\Workflow;

use App\Models\Master\User;
use Database\Factories\Workflow\WorkflowCaseHistoryUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 履歴の人(振替先と、処理対象者の変更・再展開の後の処理対象者を、その操作の履歴の行に記録する。案件の部品。追記だけ)。
 * 表 workflow_case_history_users(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.9 節)。履歴の行は同じ案件のもの(複合外部キー)。
 */
#[Fillable(['workflow_case_id', 'workflow_case_history_id', 'sequence', 'user_id'])]
class WorkflowCaseHistoryUser extends Model
{
    /** @use HasFactory<WorkflowCaseHistoryUserFactory> */
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
     * 記録した操作の履歴の行。
     *
     * @return BelongsTo<WorkflowCaseHistory, $this>
     */
    public function workflowCaseHistory(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseHistory::class);
    }

    /**
     * 振替先・変更後の処理対象者。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

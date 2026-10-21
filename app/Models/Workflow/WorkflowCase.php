<?php

namespace App\Models\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use Database\Factories\Workflow\WorkflowCaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 案件(フローの 1 回の申請。一時保存を含む)。表 workflow_cases(docs の 設計/ワークフロー/データモデル/表.md 2.5、ルートの深さ/データモデル/案件の表.md 3.1)。
 * 状態ごとに持つ列の組は検査制約が止める。案件番号は列の既定値でなく、申請の確定の時に採る。
 * 「添付を使う」は申請の時のフローの値の写しで、一時保存は NULL(設計/ワークフロー/申請の周辺/データモデル.md の 3.4 節)。
 */
#[Fillable([
    'workflow_flow_id', 'state', 'completion_reason', 'case_number', 'name', 'user_id', 'department_id', 'record_id',
    'draft_input', 'revision', 'applied_at', 'completed_at', 'workflow_flow_version_id', 'base_date',
    'is_attachment_enabled',
])]
class WorkflowCase extends Model
{
    /** @use HasFactory<WorkflowCaseFactory> */
    use HasFactory;

    /** 案件名の最大の文字数(列 name の長さ。業務処理の検証と画面の文字数の上限の正) */
    public const NAME_MAX = 1000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => WorkflowCaseState::class,
            'completion_reason' => WorkflowCompletionReason::class,
            'case_number' => 'integer',
            'draft_input' => 'array',
            'revision' => 'integer',
            'applied_at' => 'datetime',
            'completed_at' => 'datetime',
            'base_date' => 'date',
            'is_attachment_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkflowFlow, $this>
     */
    public function workflowFlow(): BelongsTo
    {
        return $this->belongsTo(WorkflowFlow::class);
    }

    /**
     * 写した版(一時保存なら null)。
     *
     * @return BelongsTo<WorkflowFlowVersion, $this>
     */
    public function workflowFlowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowFlowVersion::class);
    }

    /**
     * 申請者(一時保存した人)。
     *
     * @return BelongsTo<User, $this>
     */
    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 担当組織(申請の確定の時の申請者のカレント組織。無ければ null)。
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * 申請フォームのレコード(一時保存なら null)。
     *
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /**
     * 案件のノード(部品。案件と一緒に消える)。
     *
     * @return HasMany<WorkflowCaseNode, $this>
     */
    public function workflowCaseNodes(): HasMany
    {
        return $this->hasMany(WorkflowCaseNode::class);
    }

    /**
     * 履歴(部品。案件と一緒に消える)。
     *
     * @return HasMany<WorkflowCaseHistory, $this>
     */
    public function workflowCaseHistories(): HasMany
    {
        return $this->hasMany(WorkflowCaseHistory::class);
    }

    /**
     * 案件の辺(部品。案件と一緒に消える)。
     *
     * @return HasMany<WorkflowCaseEdge, $this>
     */
    public function workflowCaseEdges(): HasMany
    {
        return $this->hasMany(WorkflowCaseEdge::class);
    }

    /**
     * 指定(部品。案件と一緒に消える)。
     *
     * @return HasMany<WorkflowCaseAssignment, $this>
     */
    public function workflowCaseAssignments(): HasMany
    {
        return $this->hasMany(WorkflowCaseAssignment::class);
    }
}

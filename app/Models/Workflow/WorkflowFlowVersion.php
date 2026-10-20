<?php

namespace App\Models\Workflow;

use App\Models\Master\HasPeriod;
use Database\Factories\Workflow\WorkflowFlowVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * フローの版(フローの部品。期間の表の形 = 共通の規則 5 節)。表 workflow_flow_versions(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.1)。
 * 版がルート(ノード・辺・差戻しできる先)を持つ。申請に使う版 = 申請基準日に有効な版({@see HasPeriod} の effectiveOn)。
 */
#[Fillable(['workflow_flow_id', 'number', 'start_date', 'end_date', 'is_deleted'])]
class WorkflowFlowVersion extends Model
{
    /** @use HasFactory<WorkflowFlowVersionFactory> */
    use HasFactory;

    use HasPeriod;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_deleted' => 'boolean',
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
     * ルートのノード(部品。版と一緒に消える)。
     *
     * @return HasMany<WorkflowRouteNode, $this>
     */
    public function workflowRouteNodes(): HasMany
    {
        return $this->hasMany(WorkflowRouteNode::class);
    }

    /**
     * ルートの辺(部品。版と一緒に消える)。
     *
     * @return HasMany<WorkflowRouteEdge, $this>
     */
    public function workflowRouteEdges(): HasMany
    {
        return $this->hasMany(WorkflowRouteEdge::class);
    }

    /**
     * この版を写した案件。
     *
     * @return HasMany<WorkflowCase, $this>
     */
    public function workflowCases(): HasMany
    {
        return $this->hasMany(WorkflowCase::class);
    }
}

<?php

namespace App\Models\Workflow;

use App\Models\Authz\AuthzSubjectGroup;
use Database\Factories\Workflow\WorkflowFlowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * フロー(コンテンツを結ぶ、申請の単位。期間なしのマスタで、ルートは版が持つ)。表 workflow_flows(docs の 設計/ワークフロー/データモデル/表.md 2.4、
 * ルートの深さ/データモデル/定義の表.md 2.6)。フローは版を 1 つ以上持つ(業務処理の規則)。
 * 参照者の指定は用途 selection の認可の対象者(NULL = 参照者なし。生成列 purpose はアプリが書かず読まない)。
 * 「添付を使う」は申請の時に案件へ写す(設計/ワークフロー/申請の周辺/データモデル.md の 2.1 節)。
 */
#[Fillable([
    'code', 'name', 'description', 'workflow_content_id', 'authz_subject_group_id', 'sort_key', 'is_attachment_enabled',
])]
class WorkflowFlow extends Model
{
    /** @use HasFactory<WorkflowFlowFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_key' => 'integer',
            'is_attachment_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkflowContent, $this>
     */
    public function workflowContent(): BelongsTo
    {
        return $this->belongsTo(WorkflowContent::class);
    }

    /**
     * 版(部品。フローと一緒に消える)。
     *
     * @return HasMany<WorkflowFlowVersion, $this>
     */
    public function workflowFlowVersions(): HasMany
    {
        return $this->hasMany(WorkflowFlowVersion::class);
    }

    /**
     * 参照者の指定(参照者なしなら null)。
     *
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function referrers(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class, 'authz_subject_group_id');
    }

    /**
     * @return HasMany<WorkflowCase, $this>
     */
    public function workflowCases(): HasMany
    {
        return $this->hasMany(WorkflowCase::class);
    }
}

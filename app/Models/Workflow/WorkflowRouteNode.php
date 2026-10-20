<?php

namespace App\Models\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\CompanyPost;
use Database\Factories\Workflow\WorkflowRouteNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ルートのノード(版の部品)。表 workflow_route_nodes(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.3)。
 * 位置 1 だけが申請のノード。種類と処理対象者の指定の種類・指定の列・人数・可決の条件・申請者承認防止・対の終了の組は検査制約が止める。
 * 指定するノード・対の終了は同じ版のノード(複合外部キー)。処理対象者の指定(fixed)は用途 selection の認可の対象者(生成列 purpose はアプリが書かず読まない)。
 * 処理期限の日数と確認する人はステップの設定の列(設計/ワークフロー/案件の運用/データモデル/表.md の 2.3 節)。確認する人も用途 selection の対象者。
 */
#[Fillable([
    'workflow_flow_version_id', 'code', 'name', 'kind', 'position', 'target_kind', 'authz_subject_group_id', 'relative_base', 'relative_scope', 'company_post_id',
    'designator_workflow_route_node_id', 'min_assignees', 'max_assignees', 'decision_rule', 'decision_value', 'prevents_applicant_approval', 'closing_workflow_route_node_id',
    'deadline_days', 'confirm_authz_subject_group_id',
])]
class WorkflowRouteNode extends Model
{
    /** @use HasFactory<WorkflowRouteNodeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WorkflowNodeKind::class,
            'position' => 'integer',
            'target_kind' => WorkflowTargetKind::class,
            'relative_base' => WorkflowRelativeBase::class,
            'relative_scope' => WorkflowRelativeScope::class,
            'min_assignees' => 'integer',
            'max_assignees' => 'integer',
            'decision_rule' => WorkflowDecisionRule::class,
            'decision_value' => 'integer',
            'prevents_applicant_approval' => 'boolean',
            'deadline_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkflowFlowVersion, $this>
     */
    public function workflowFlowVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowFlowVersion::class);
    }

    /**
     * 処理対象者の指定(対象者で指定のノードだけ)。
     *
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function authzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class);
    }

    /**
     * 確認する人(申請・承認のノードだけ。null = 確認なし)。
     *
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function confirmAuthzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class, 'confirm_authz_subject_group_id');
    }

    /**
     * 起点で指定の役職(起点で指定のノードだけ。null = 役職で絞らない)。
     *
     * @return BelongsTo<CompanyPost, $this>
     */
    public function companyPost(): BelongsTo
    {
        return $this->belongsTo(CompanyPost::class);
    }

    /**
     * 処理の時に指定のノードの、指定するノード(同じ版の承認のノード)。
     *
     * @return BelongsTo<self, $this>
     */
    public function designator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'designator_workflow_route_node_id');
    }

    /**
     * 分岐開始・同期開始の対の終了(同じ版のノード)。
     *
     * @return BelongsTo<self, $this>
     */
    public function closing(): BelongsTo
    {
        return $this->belongsTo(self::class, 'closing_workflow_route_node_id');
    }

    /**
     * このノードから出る辺。
     *
     * @return HasMany<WorkflowRouteEdge, $this>
     */
    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(WorkflowRouteEdge::class, 'from_workflow_route_node_id');
    }

    /**
     * このノードに入る辺。
     *
     * @return HasMany<WorkflowRouteEdge, $this>
     */
    public function incomingEdges(): HasMany
    {
        return $this->hasMany(WorkflowRouteEdge::class, 'to_workflow_route_node_id');
    }

    /**
     * 差戻しできる先(このノードを差戻し元とする行。部品)。
     *
     * @return HasMany<WorkflowRouteSendBack, $this>
     */
    public function workflowRouteSendBacks(): HasMany
    {
        return $this->hasMany(WorkflowRouteSendBack::class);
    }
}

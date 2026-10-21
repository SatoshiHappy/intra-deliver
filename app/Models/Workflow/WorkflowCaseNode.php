<?php

namespace App\Models\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use Database\Factories\Workflow\WorkflowCaseNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * 案件のノード(申請の時に版のルートのノードを写した行と、横配置・縦配置の展開の行。案件の部品)。表 workflow_case_nodes
 * (docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.2)。写した列は後で変えない。展開の行は親と番号を持ち、位置・コードを持たない。
 * 処理済みのノードだけが、処理済みにした履歴の行(このノードの履歴)を指す。指すノード(親・指定するノード・対の終了)は同じ案件のノード(複合外部キー)。
 * 生成列 purpose はアプリが書かず読まない。到達日時と到達の前処理者・起点の組織は計画が到達のときに書き、期限の日数と確認する人は写しの列
 * (設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節)。現在のノード(承認待ち・再申請待ち・保留)は到達日時を持つ(検査制約)。
 * 「添付を追加できない」も写しの列で、展開の行は親の値を写す(設計/ワークフロー/申請の周辺/データモデル.md の 3.1 節)。
 */
#[Fillable([
    'workflow_case_id', 'position', 'code', 'name', 'kind', 'target_kind', 'authz_subject_group_id', 'relative_base', 'relative_scope', 'company_post_id',
    'designator_workflow_case_node_id', 'min_assignees', 'max_assignees', 'decision_rule', 'decision_value', 'prevents_applicant_approval', 'closing_workflow_case_node_id',
    'parent_workflow_case_node_id', 'sequence', 'state', 'workflow_case_history_id',
    'reached_at', 'previous_user_id', 'previous_department_id', 'deadline_days', 'confirm_authz_subject_group_id',
    'prevents_attachment_add',
])]
class WorkflowCaseNode extends Model
{
    /** @use HasFactory<WorkflowCaseNodeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => WorkflowNodeKind::class,
            'target_kind' => WorkflowTargetKind::class,
            'relative_base' => WorkflowRelativeBase::class,
            'relative_scope' => WorkflowRelativeScope::class,
            'min_assignees' => 'integer',
            'max_assignees' => 'integer',
            'decision_rule' => WorkflowDecisionRule::class,
            'decision_value' => 'integer',
            'prevents_applicant_approval' => 'boolean',
            'sequence' => 'integer',
            'state' => WorkflowNodeState::class,
            'reached_at' => 'datetime',
            'deadline_days' => 'integer',
            'prevents_attachment_add' => 'boolean',
        ];
    }

    /**
     * 表示名の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3): 展開の行は「{親の名前} {子の名前}」(間は半角の空白 1 つ)、
     * ほかは写した名前。展開の行は親(parent)を読み込んでから呼ぶ(一覧・詳細・失敗の文言が同じ値を使う)。
     */
    public function displayName(): string
    {
        if ($this->parent_workflow_case_node_id === null) {
            return $this->name;
        }

        $parent = $this->parent ?? throw new LogicException("展開の行 {$this->id} の親が無い");

        return "{$parent->name} {$this->name}";
    }

    /**
     * 写した並びの 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3): 親の position(展開の行は親の)→ 展開の行の sequence(親が先)→ id。
     * 展開の行は親(parent)を読み込んでから使う(一覧・詳細・現在のノードが同じ並びを使う)。
     */
    public static function compareInRoute(self $a, self $b): int
    {
        return [$a->routePosition(), $a->sequence ?? 0, $a->id] <=> [$b->routePosition(), $b->sequence ?? 0, $b->id];
    }

    /**
     * @return BelongsTo<WorkflowCase, $this>
     */
    public function workflowCase(): BelongsTo
    {
        return $this->belongsTo(WorkflowCase::class);
    }

    /**
     * 写した処理対象者の指定(対象者で指定のノードだけ)。
     *
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function authzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class);
    }

    /**
     * 写した確認する人(展開の行は持たない)。
     *
     * @return BelongsTo<AuthzSubjectGroup, $this>
     */
    public function confirmAuthzSubjectGroup(): BelongsTo
    {
        return $this->belongsTo(AuthzSubjectGroup::class, 'confirm_authz_subject_group_id');
    }

    /**
     * 到達の前処理者(前のノードからの到達で展開したときの起点の人。記録が無ければ null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function previousUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_user_id');
    }

    /**
     * 到達の起点の組織(前処理者と同時に書く)。
     *
     * @return BelongsTo<Department, $this>
     */
    public function previousDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'previous_department_id');
    }

    /**
     * 写した起点で指定の役職。
     *
     * @return BelongsTo<CompanyPost, $this>
     */
    public function companyPost(): BelongsTo
    {
        return $this->belongsTo(CompanyPost::class);
    }

    /**
     * 写した指定するノード(同じ案件のノード)。
     *
     * @return BelongsTo<self, $this>
     */
    public function designator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'designator_workflow_case_node_id');
    }

    /**
     * 写した対の終了(同じ案件のノード)。
     *
     * @return BelongsTo<self, $this>
     */
    public function closing(): BelongsTo
    {
        return $this->belongsTo(self::class, 'closing_workflow_case_node_id');
    }

    /**
     * 展開の行の親(横配置・縦配置のノード。展開の行でなければ null)。
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_workflow_case_node_id');
    }

    /**
     * 展開の行(このノードを親とする行)。
     *
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_workflow_case_node_id');
    }

    /**
     * このノードを処理済みにした履歴の行(処理済みでなければ null)。
     *
     * @return BelongsTo<WorkflowCaseHistory, $this>
     */
    public function workflowCaseHistory(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseHistory::class);
    }

    /**
     * 処理対象者(部品。現在のノードだけが持つ)。
     *
     * @return HasMany<WorkflowCaseNodeTarget, $this>
     */
    public function workflowCaseNodeTargets(): HasMany
    {
        return $this->hasMany(WorkflowCaseNodeTarget::class);
    }

    /**
     * このノードを処理した履歴の行(引戻しは、引戻した人のノード)。
     *
     * @return HasMany<WorkflowCaseHistory, $this>
     */
    public function workflowCaseHistories(): HasMany
    {
        return $this->hasMany(WorkflowCaseHistory::class);
    }

    /**
     * 写した差戻しできる先(このノードを差戻し元とする行。部品)。
     *
     * @return HasMany<WorkflowCaseSendBack, $this>
     */
    public function workflowCaseSendBacks(): HasMany
    {
        return $this->hasMany(WorkflowCaseSendBack::class);
    }

    /**
     * 並びの位置(展開の行は親の position)。
     */
    private function routePosition(): int
    {
        if ($this->parent_workflow_case_node_id === null) {
            return (int) $this->position;
        }

        return (int) ($this->parent ?? throw new LogicException("展開の行 {$this->id} の親が無い"))->position;
    }
}

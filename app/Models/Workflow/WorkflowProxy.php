<?php

namespace App\Models\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use Database\Factories\Workflow\WorkflowProxyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 代理(代理元が代理先に処理の代わりを委託する設定)。表 workflow_proxies(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.12 節)。
 * 期間は半開区間 [start_date, end_date)(end_date が null = 上限なし)。同じ組の重なりを許す。フローが null = 全フロー。行は登録と削除だけ。
 */
#[Fillable([
    'principal_user_id', 'proxy_user_id', 'kind', 'workflow_flow_id', 'start_date', 'end_date', 'registered_by_user_id',
])]
class WorkflowProxy extends Model
{
    /** @use HasFactory<WorkflowProxyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WorkflowProxyKind::class,
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * 代理元。
     *
     * @return BelongsTo<User, $this>
     */
    public function principalUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'principal_user_id');
    }

    /**
     * 代理先。
     *
     * @return BelongsTo<User, $this>
     */
    public function proxyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proxy_user_id');
    }

    /**
     * 特定業務代理のフロー(null = 全フロー)。
     *
     * @return BelongsTo<WorkflowFlow, $this>
     */
    public function workflowFlow(): BelongsTo
    {
        return $this->belongsTo(WorkflowFlow::class);
    }

    /**
     * 登録した人(代理元本人か、システム管理者)。
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }
}

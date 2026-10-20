<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowProxy;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 代理の削除(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)。自分の代理は {@see self::executeOwn()}、
 * 代理の管理は {@see self::execute()}(認可はルート)。案件の行の鍵を取らない(設計/ワークフロー/案件の運用/データモデル/README.md の 4.2 節)。
 */
class DeleteProxy
{
    /**
     * 代理の管理の削除(どの行も消せる)。
     *
     * @throws ModelNotFoundException<WorkflowProxy> 行が無いとき
     */
    public function execute(int $proxyId): void
    {
        WorkflowProxy::query()->findOrFail($proxyId)->delete();
    }

    /**
     * 自分の代理の削除(代理元が自分の行だけ)。
     *
     * @throws AuthorizationException ゲストのとき
     * @throws ModelNotFoundException<WorkflowProxy> 行が無い・代理元が自分でないとき
     */
    public function executeOwn(AccessContext $context, int $proxyId): void
    {
        $userId = $context->userId ?? throw new AuthorizationException;

        WorkflowProxy::query()->where('principal_user_id', $userId)->findOrFail($proxyId)->delete();
    }
}

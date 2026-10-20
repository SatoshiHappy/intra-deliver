<?php

namespace App\Actions\Authz;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Models\Authz\AuthzPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 認可のポリシーを消す(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.3。直接の設定を消すと、継承か設定なしに戻る)。
 */
class DeletePolicy
{
    use CommonMasterWriteLock;

    /**
     * @throws ModelNotFoundException<AuthzPolicy> ポリシーが無いとき
     */
    public function execute(int $policyId): void
    {
        $this->writeCommonMaster(fn () => AuthzPolicy::query()->findOrFail($policyId)->delete());
    }
}

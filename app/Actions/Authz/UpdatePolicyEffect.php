<?php

namespace App\Actions\Authz;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 認可のポリシーのエフェクトを変える(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.3、設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies)。
 * 組(リソースグループ・対象者・タイプ・アクション)は変えない。
 */
class UpdatePolicyEffect
{
    use CommonMasterWriteLock;

    /**
     * @throws ModelNotFoundException<AuthzPolicy> ポリシーが無いとき
     */
    public function execute(int $policyId, PolicyEffect $effect): AuthzPolicy
    {
        return $this->writeCommonMaster(function () use ($policyId, $effect) {
            $policy = AuthzPolicy::query()->findOrFail($policyId);
            $policy->update(['effect' => $effect]);

            return $policy;
        });
    }
}

<?php

namespace App\Queries\Authz;

use App\Enums\Authz\AuthorizationResult;
use App\Enums\Authz\PolicyEffect;

/**
 * 判定の結果と、効いている設定(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.1。{@see AuthorizationQuery::explain()} が返す)。
 */
final readonly class AuthorizationExplanation
{
    /**
     * @param  list<EffectivePolicy>  $policies  効いている設定(順は問わない)。閉塞でも閉塞でないときと同じに作る
     */
    public function __construct(
        public AuthorizationResult $result,
        public array $policies,
    ) {}

    /**
     * 効いたポリシー: 結果が許可なら合う許可のすべて、禁止なら合う禁止のすべて(空なら、どの対象者にも合わない・設定が無い既定の禁止)、
     * 閉塞なら空。
     *
     * @return list<EffectivePolicy>
     */
    public function decidingPolicies(): array
    {
        $effect = match ($this->result) {
            AuthorizationResult::Permit => PolicyEffect::Permit,
            AuthorizationResult::Deny => PolicyEffect::Deny,
            AuthorizationResult::Block => null,
        };

        return array_values(array_filter(
            $this->policies,
            fn (EffectivePolicy $policy) => $effect !== null && $policy->matches && $policy->effect === $effect,
        ));
    }
}

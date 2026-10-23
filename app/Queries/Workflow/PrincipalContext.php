<?php

namespace App\Queries\Workflow;

use App\Support\AccessContext;

/**
 * 判定を求める者を代理元にする所の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節)。判定の入力の形を変えずに
 * 代理元で判定するため、`AccessContext` の値を代理元で作る。代理元が自分なら要求の値をそのまま返す(自分の選んだ組織を保つ)。
 */
final class PrincipalContext
{
    public static function of(AccessContext $context, int $principalUserId): AccessContext
    {
        if ($principalUserId === $context->userId) {
            return $context;
        }

        return new AccessContext(
            $principalUserId,
            $context->ipv4Address,
            null,
            $context->date,
            $context->localeCode,
            $context->visitorId,
        );
    }
}

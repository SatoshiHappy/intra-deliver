<?php

namespace App\Enums\Authz;

/**
 * 認可のポリシーのエフェクト(authz_policies.effect)。行が無い = 未設定。
 */
enum PolicyEffect: string
{
    case Permit = 'permit';
    case Deny = 'deny';
}

<?php

namespace App\Actions\Ai\Core\Ported;

/**
 * 移した版が関数の呼び出しで認証を読むのは禁止(式の型の規則が止める)
 */
final class PortedReadsUser
{
    public function signedIn(): bool
    {
        return auth()->check();
    }
}

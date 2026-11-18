<?php

namespace App\Actions\Ai\Core\Ported;

/**
 * 移した版が関数の呼び出しでセッションを読むのは禁止(式の型の規則が止める)
 */
final class PortedReadsSession
{
    public function token(): mixed
    {
        return session()->get('token');
    }
}

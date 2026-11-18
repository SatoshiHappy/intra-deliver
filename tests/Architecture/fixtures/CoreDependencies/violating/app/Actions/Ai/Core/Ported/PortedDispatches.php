<?php

namespace App\Actions\Ai\Core\Ported;

/**
 * 移した版が関数の呼び出しでキューに積むのは禁止(式の型の規則が止める)
 */
final class PortedDispatches
{
    public function later(): void
    {
        dispatch(fn () => null);
    }
}

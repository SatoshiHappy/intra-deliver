<?php

namespace App\Actions\Ai\Core\Ported;

/**
 * 移した版が関数の呼び出しで DB に届くのは禁止(phpat は関数を数えないので、式の型の規則が止める)
 */
final class PortedCallsDatabase
{
    /** @return list<object> */
    public function rows(): array
    {
        return app('db')->select('select 1');
    }
}

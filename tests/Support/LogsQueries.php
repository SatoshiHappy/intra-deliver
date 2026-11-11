<?php

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * 呼び出し 1 回の間に DB に送った問い合わせの数とログ(問い合わせの数が増えないことを確かめるテストのため)。
 */
trait LogsQueries
{
    private function queriesOf(Closure $call): int
    {
        return count($this->queryLogOf($call));
    }

    /**
     * @return array<array{query: string, bindings: array<int, mixed>, time: float|null}> 形 = DB::getQueryLog()
     */
    private function queryLogOf(Closure $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $call();
        } finally {
            DB::disableQueryLog();
        }

        return DB::getQueryLog();
    }
}

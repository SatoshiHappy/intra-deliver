<?php

namespace App\Actions\Records;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * レコードの書き込みの業務処理のトランザクション。互いを参照するレコードの同時の削除などで負けたデッドロックを、500 にせずやり直させる。
 */
trait RecordWriteTransaction
{
    /** 試行の回数(負けた側は 2 回目で相手の後に走る。待ちの輪の検出は 1 回ごとに deadlock_timeout(既定 1 秒)かかるので増やさない) */
    private const RECORD_WRITE_ATTEMPTS = 3;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function writeRecords(Closure $callback): mixed
    {
        return DB::transaction($callback, self::RECORD_WRITE_ATTEMPTS);
    }
}

<?php

namespace App\Actions\Master;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * 共通マスタ(Master と Authz)の書き込みの業務処理が通る、ただ 1 つの入口(トランザクション + 勧告ロック)。
 *
 * 業務処理で止める規則(docs の 設計/共通マスタ/共通の規則.md 5.5 と各節)は「読んでから書く」ので、PostgreSQL の既定の分離レベル
 * (READ COMMITTED)では、同時に走る 2 つの業務処理が互いの書き込みを見ずに両方とも規則を通りうる。これを防ぐため、
 * トランザクションの先頭で、共通マスタの全体で 1 つのトランザクションレベルの勧告ロック(pg_advisory_xact_lock)を取り、
 * 書き込みを直列にする(メインの決定 2026-10-04。規則ごとに扱いを変えない。規則が Master と Authz の表にまたがるので、ロックも 1 つ)。
 * ロックはトランザクションの終わりで外れる。同じ接続が持っているロックを重ねて取るのは常に成功する(業務処理の中から別の業務処理を呼べる)。
 */
trait WritesCommonMaster
{
    /** 勧告ロックの鍵(bigint の 1 つの鍵の空間。共通マスタの書き込みの全体で 1 つ) */
    public const COMMON_MASTER_LOCK_KEY = 4_100_000_001;

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function writeCommonMaster(Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::COMMON_MASTER_LOCK_KEY]);

            return $callback();
        });
    }
}

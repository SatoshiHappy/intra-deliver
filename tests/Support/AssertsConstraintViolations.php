<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * DB の制約が書き込みを拒むことを確かめるアサーション(共通マスタの表の制約のテスト用)。
 *
 * 書き込みはセーブポイント(入れ子の DB::transaction)の中で行い、拒まれた後もテストのトランザクションを使い続けられるようにする。
 * 制約の名前は PostgreSQL の識別子の上限(63 バイト)に切り詰めて照合する(Laravel の既定の名前は上限を超えることがあり、PostgreSQL が切り詰める)。
 */
trait AssertsConstraintViolations
{
    private const IDENTIFIER_MAX_BYTES = 63;

    /**
     * 書き込みが、名前を指定した制約(検査・一意・排他・外部キー)で拒まれることを確かめる。
     *
     * @param  Closure(): mixed  $write
     */
    protected function assertRejectedBy(string $constraint, Closure $write): void
    {
        $message = $this->violationMessage($write);

        $this->assertStringContainsString('"'.substr($constraint, 0, self::IDENTIFIER_MAX_BYTES).'"', $message);
    }

    /**
     * 書き込みが、列の NOT NULL で拒まれることを確かめる。
     *
     * @param  Closure(): mixed  $write
     */
    protected function assertRejectedAsNull(string $table, string $column, Closure $write): void
    {
        $message = $this->violationMessage($write);

        $this->assertStringContainsString("null value in column \"{$column}\" of relation \"{$table}\" violates not-null constraint", $message);
    }

    /**
     * 書き込みを実行し、DB が拒んだときのメッセージを返す。拒まれなければテストを失敗させる。
     *
     * @param  Closure(): mixed  $write
     */
    private function violationMessage(Closure $write): string
    {
        try {
            DB::transaction($write);
        } catch (QueryException $exception) {
            return $exception->getMessage();
        }

        $this->fail('DB の制約に拒まれなかった');
    }
}

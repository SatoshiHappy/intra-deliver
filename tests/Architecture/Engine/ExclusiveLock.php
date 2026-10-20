<?php

namespace Tests\Architecture\Engine;

use RuntimeException;

/**
 * ロックファイルの排他ロック(flock)を取って処理を走らせる(汎用)。同じロックファイルを使うプロセスどうしは 1 本ずつ走る。
 * ロックは処理の終了時(例外を含む)に外す。プロセスが落ちたときは OS が外す(残ったロックで後続が止まらない)。
 */
final class ExclusiveLock
{
    /**
     * $lockFile(無ければ作る)の排他ロックを待って取り、$callback を走らせて結果を返す。
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws RuntimeException ロックファイルを開けない・ロックを取れないとき
     */
    public static function run(string $lockFile, callable $callback): mixed
    {
        $handle = fopen($lockFile, 'c');

        if ($handle === false) {
            throw new RuntimeException("ロックファイルを開けない: {$lockFile}");
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException("ロックを取れない: {$lockFile}");
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}

<?php

namespace App\Support;

use Pdo\Pgsql;

/**
 * 共有 → PDO のドライバごとの派生(DB)は禁止
 */
final class ReachesPdoDriver
{
    public function usesPgsql(Pgsql $pgsql): Pgsql
    {
        return $pgsql;
    }
}

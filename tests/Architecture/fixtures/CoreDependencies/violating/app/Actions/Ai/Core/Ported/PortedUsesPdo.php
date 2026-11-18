<?php

namespace App\Actions\Ai\Core\Ported;

use PDO;

/**
 * 移した版が PHP の標準の中の DB の型に依存するのは禁止
 */
final class PortedUsesPdo
{
    public function __construct(public PDO $pdo) {}
}

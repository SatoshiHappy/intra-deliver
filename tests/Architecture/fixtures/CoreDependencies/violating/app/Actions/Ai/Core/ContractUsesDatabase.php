<?php

namespace App\Actions\Ai\Core;

use PDOStatement;

/**
 * 約束が PHP の標準の中の DB の型に依存するのは禁止(標準を許す並びから DB の型を除く)
 */
final class ContractUsesDatabase
{
    public function __construct(public PDOStatement $statement) {}
}

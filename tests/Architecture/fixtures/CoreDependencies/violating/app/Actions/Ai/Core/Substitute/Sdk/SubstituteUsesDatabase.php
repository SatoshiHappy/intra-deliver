<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use PDOStatement;

/**
 * 代わりの版の変換の 1 か所が PHP の標準の中の DB の型に依存するのは禁止
 */
final class SubstituteUsesDatabase
{
    public function __construct(public PDOStatement $statement) {}
}

<?php

namespace App\Actions\Ai\Core;

use Illuminate\Contracts\Container\Container;

/**
 * 約束がコンテナに依存するのは禁止(コンテナを通してシステムの型に届く)
 */
final class ContractUsesContainer
{
    public function __construct(public Container $container) {}
}

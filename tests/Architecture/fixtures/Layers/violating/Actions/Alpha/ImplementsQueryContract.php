<?php

namespace App\Actions\Alpha;

use App\Queries\Alpha\AlphaContract;

/**
 * 業務処理が読み取りの interface を実装するのは禁止(読み取りから書き込みに届く)
 */
final class ImplementsQueryContract implements AlphaContract
{
    public function find(): string
    {
        return 'found';
    }
}

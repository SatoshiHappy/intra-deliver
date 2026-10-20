<?php

namespace App\Queries\Alpha;

/**
 * 読み取りの interface を読み取りが実装するのは可
 */
final class ImplementsContract implements AlphaContract
{
    public function find(): string
    {
        return 'found';
    }
}

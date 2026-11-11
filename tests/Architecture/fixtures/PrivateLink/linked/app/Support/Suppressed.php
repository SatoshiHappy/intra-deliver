<?php

namespace App\Support;

/**
 * 抑制の注釈(台帳⑧に無い)
 */
final class Suppressed
{
    public function length(): int
    {
        return strlen('x'); // @phpstan-ignore function.notFound
    }
}

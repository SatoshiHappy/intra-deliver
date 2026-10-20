<?php

declare(strict_types=1);

namespace App\Support;

use Stringable;

/**
 * namespace・declare・use とクラス 1 つ
 */
final class OneClass implements Stringable
{
    use Reusable;

    public function __toString(): string
    {
        return 'one';
    }
}

<?php

namespace App\Support\OctaneFixture;

use Closure;

final class HoldsStaticState
{
    private static int $calls = 0;

    public function count(): int
    {
        static $cache = 0;
        $cache++;
        self::$calls++;

        return $cache + self::$calls;
    }

    /**
     * @return Closure(): int
     */
    public function counter(): Closure
    {
        return function (): int {
            static $closureCalls = 0;

            return ++$closureCalls;
        };
    }
}

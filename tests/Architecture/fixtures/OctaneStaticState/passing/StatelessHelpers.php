<?php

namespace App\Support\OctaneFixture;

use Closure;

/**
 * 定数・static メソッド(純関数)・static クロージャ・static の戻り型は状態ではない。
 */
final class StatelessHelpers
{
    public const PREFIX = 'fixture-';

    public static function prefixed(string $value): string
    {
        return self::PREFIX.$value;
    }

    public static function make(): static
    {
        return new static;
    }

    /**
     * @return Closure(string): string
     */
    public function prefixer(): Closure
    {
        return static fn (string $value): string => self::prefixed($value);
    }
}

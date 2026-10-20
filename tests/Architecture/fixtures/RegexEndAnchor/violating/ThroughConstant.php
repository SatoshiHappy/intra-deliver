<?php

namespace Fixture\RegexEndAnchor;

/**
 * 定数を通したパターンも、型が定数の文字列に決まるので止まる。
 */
final class ThroughConstant
{
    private const PATTERN = '/^\d+$/';

    public function matches(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}

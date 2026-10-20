<?php

namespace Fixture\RegexEndAnchor;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。
 */
final class Suppressed
{
    public function matches(string $value): bool
    {
        // @phpstan-ignore regexEndAnchor
        return preg_match('/^x$/', $value) === 1;
    }
}

<?php

namespace Fixture\RegexEndAnchor;

use PHPUnit\Framework\Assert;

/**
 * 修飾子 D・m の無い $ は、パターンを受けるどの口でも止まる。
 */
final class BareDollar
{
    public function matches(string $value): bool
    {
        return preg_match('/^[a-z]+$/', $value) === 1;
    }

    public function replaces(string $value): ?string
    {
        return preg_replace(['/^a/D', '/b$/'], 'c', $value);
    }

    public function replacesByKey(string $value): ?string
    {
        return preg_replace_callback_array(['/c$/' => fn (array $match): string => 'd'], $value);
    }

    public function asserts(string $value): void
    {
        Assert::assertMatchesRegularExpression('/^\d+$/', $value);
    }

    public function inlineModifiers(string $value): bool
    {
        return preg_match('/(?m:a)b$/', $value) === 1
            || preg_match('/(?-m)a$/', $value) === 1;
    }

    public function turnedOffModifiers(string $value): bool
    {
        return preg_match('/(?-m)a$/m', $value) === 1
            || preg_match('/(?m-m)a$/', $value) === 1
            || preg_match('/(?m)a(?-m)b$/', $value) === 1
            || preg_match('/(?-x)a # $/x', $value) === 1;
    }
}

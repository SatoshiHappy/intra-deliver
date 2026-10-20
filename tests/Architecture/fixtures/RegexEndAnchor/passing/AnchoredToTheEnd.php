<?php

namespace Fixture\RegexEndAnchor;

use PHPUnit\Framework\Assert;

/**
 * 末尾の改行を受け入れない書き方と、正規表現の $ でないもの。
 * 型が定数の文字列に決まらないパターン(preg_quote とつないだもの)は、規則が見ない(止まらない限界)。
 */
final class AnchoredToTheEnd
{
    private const PATTERN = '/^\d+$/D';

    public function matches(string $value): bool
    {
        return preg_match('/^[a-z]+$/D', $value) === 1
            || preg_match('/\A[a-z]+\z/', $value) === 1
            || preg_match('/^[a-z]+$/m', $value) === 1
            || preg_match('/^price \$\d+/', $value) === 1
            || preg_match('/^[$]\d+/', $value) === 1
            || preg_match('/^[]$]+/', $value) === 1
            || preg_match('/^\Q1$\E/', $value) === 1
            || preg_match(self::PATTERN, $value) === 1
            || preg_match('/(?m)a$/', $value) === 1
            || preg_match('/a(?#$)/', $value) === 1
            || preg_match('/[[:alpha:]$]/', $value) === 1
            || preg_match("/a # \$\n/x", $value) === 1;
    }

    public function replaces(string $value): ?string
    {
        return preg_replace('/(a)(b)/', '$2$1', $value);
    }

    public function notConstant(string $value, string $suffix): bool
    {
        return preg_match('/'.preg_quote($suffix, '/').'$/', $value) === 1;
    }

    public function asserts(string $value): void
    {
        Assert::assertMatchesRegularExpression('/^\d+$/D', $value);
        Assert::assertSame('total$', $value);
    }
}

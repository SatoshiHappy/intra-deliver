<?php

namespace Tests\Unit\Support;

use App\Support\PositiveIds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * クエリの識別子の読み方: 1 から始まる 18 桁までの 10 進の文字列だけを読み、ほかは null。
 */
final class PositiveIdsTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int|null}>
     */
    public static function values(): array
    {
        return [
            '1 桁' => ['1', 1],
            '複数の桁' => ['120', 120],
            '18 桁' => [str_repeat('9', PositiveIds::MAX_DIGITS), 999999999999999999],
            '19 桁' => [str_repeat('9', PositiveIds::MAX_DIGITS + 1), null],
            'ゼロ' => ['0', null],
            '先頭の 0' => ['01', null],
            '負の数' => ['-1', null],
            '符号つき' => ['+1', null],
            '小数' => ['1.5', null],
            '文字' => ['abc', null],
            '空' => ['', null],
            '前の空白' => [' 1', null],
            '後ろの空白' => ['1 ', null],
            '後ろの改行' => ["1\n", null],
            '整数の値' => [1, null],
            '配列' => [['1'], null],
            '無い' => [null, null],
        ];
    }

    #[DataProvider('values')]
    public function test_only_a_positive_decimal_string_within_the_limit_is_read(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, PositiveIds::of($value));
    }
}

<?php

namespace Tests\Unit\Support;

use App\Support\PageNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 一覧のページ番号の 1 か所(案件・通知・業務データの一覧): 1 から始まる 10 進の整数の文字列だけを読み(ほかは 1)、最後のページより大きければ
 * 最後のページに詰める(件数 0 なら 1)。
 */
final class PageNumbersTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int, int}>
     */
    public static function pages(): array
    {
        return [
            '1 ページ目' => ['1', 41, 1],
            '最後のページ' => ['3', 41, 3],
            '最後のページより大きい' => ['4', 41, 3],
            'ずっと大きい' => ['999999999', 41, 3],
            'ちょうど割り切れる件数' => ['3', 40, 2],
            '件数 0' => ['2', 0, 1],
            'ゼロ' => ['0', 41, 1],
            '負の数' => ['-1', 41, 1],
            '小数' => ['1.5', 41, 1],
            '文字' => ['abc', 41, 1],
            '先頭の 0' => ['02', 41, 1],
            '10 桁' => ['1000000000', 41, 1],
            '前後の空白' => [' 2', 41, 1],
            '整数の値' => [2, 41, 1],
            '配列' => [['2'], 41, 1],
            '無い' => [null, 41, 1],
        ];
    }

    #[DataProvider('pages')]
    public function test_reads_the_page_and_clamps_it_to_the_last_page(mixed $page, int $total, int $expected): void
    {
        $this->assertSame($expected, PageNumbers::of($page, $total, 20));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function requestedPages(): array
    {
        return [
            '2 ページ目' => ['2', 2],
            '大きい数' => ['999999999', 999999999],
            'ゼロ' => ['0', 1],
            '負の数' => ['-1', 1],
            '文字' => ['abc', 1],
            '10 桁' => ['1000000000', 1],
            '整数の値' => [2, 1],
            '無い' => [null, 1],
        ];
    }

    #[DataProvider('requestedPages')]
    public function test_requested_reads_only_a_positive_decimal_string(mixed $page, int $expected): void
    {
        $this->assertSame($expected, PageNumbers::requested($page));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function clampedPages(): array
    {
        return [
            '範囲の中' => [2, 41, 2],
            '最後のページ' => [3, 41, 3],
            '最後のページより大きい' => [99, 41, 3],
            '1 より小さい' => [0, 41, 1],
            '件数 0' => [5, 0, 1],
            '件数 0 の 1 ページ目' => [1, 0, 1],
        ];
    }

    #[DataProvider('clampedPages')]
    public function test_clamp_limits_the_page_to_the_last_page(int $requested, int $total, int $expected): void
    {
        $this->assertSame($expected, PageNumbers::clamp($requested, $total, 20));
    }
}

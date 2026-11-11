<?php

namespace Tests\Unit\Support;

use App\Support\PageNumbers;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 一覧のページの数え方の 1 か所(案件・通知・業務データの一覧と、業務データの公開の口): クエリの page の読み方、最後のページへの詰め方、
 * 最後のページ(1 ページの件数が整数の最大でもあふれない)、1 ページの件数の確かめ。
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

    /**
     * @return array<string, array{int, int<1, max>, int}>
     */
    public static function lastPages(): array
    {
        return [
            '件数 0' => [0, 20, 1],
            '件数 1' => [1, 20, 1],
            'ちょうど割り切れる' => [40, 20, 2],
            '割り切れない' => [41, 20, 3],
            '1 ページの件数 1' => [3, 1, 3],
            '1 ページの件数が整数の最大' => [41, PHP_INT_MAX, 1],
            '件数が整数の最大' => [PHP_INT_MAX, 1, PHP_INT_MAX],
            '件数も 1 ページの件数も整数の最大' => [PHP_INT_MAX, PHP_INT_MAX, 1],
        ];
    }

    /**
     * @param  int<1, max>  $perPage
     */
    #[DataProvider('lastPages')]
    public function test_last_page_does_not_overflow(int $total, int $perPage, int $expected): void
    {
        $this->assertSame($expected, PageNumbers::lastPage($total, $perPage));
    }

    public function test_clamp_with_the_largest_number_a_page_is_the_first_page(): void
    {
        $this->assertSame(1, PageNumbers::clamp(5, 41, PHP_INT_MAX));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function perPages(): array
    {
        return [
            '負の数' => [-1, true],
            '0 件' => [0, true],
            '1 件' => [1, false],
            '2 件' => [2, false],
            '整数の最大' => [PHP_INT_MAX, false],
        ];
    }

    #[DataProvider('perPages')]
    public function test_a_number_a_page_below_one_is_a_logic_error(int $perPage, bool $rejected): void
    {
        if ($rejected) {
            $this->expectException(LogicException::class);
        }

        PageNumbers::throwIfPerPageBelowOne($perPage);
        $this->addToAssertionCount(1);
    }
}

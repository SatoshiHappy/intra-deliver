<?php

namespace Tests\Unit\UpdateCheck;

use PHPUnit\Framework\Attributes\DataProvider;
use UpdateCheck\Markdown;
use UpdateCheck\Text;

/**
 * 外から取った文字列の無害化(方針 3 節): バッククォート・縦線・改行を除いてからコードで囲む。
 */
final class MarkdownTest extends UpdateCheckTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function inputs(): array
    {
        return [
            '通常' => ['pkg/name', '`pkg/name`'],
            'バッククォートを除く' => ['a`b`c', '`a b c`'],
            '縦線を除く(表を崩さない)' => ['a|b', '`a b`'],
            '改行を除く' => ["a\r\nb\nc", '`a b c`'],
            '通知とリンクはコードの中で無害' => ['@octocat fixes #1', '`@octocat fixes #1`'],
            '空は印を出す' => ['  ', '(空)'],
        ];
    }

    #[DataProvider('inputs')]
    public function test_code_は外の文字列を除いてからコードで囲む(string $input, string $expected): void
    {
        $this->assertSame($expected, Markdown::code($input));
    }

    public function test_code_は長い文字列を切る(): void
    {
        $code = Markdown::code(str_repeat('あ', 500));

        $this->assertSame(Markdown::CELL_LIMIT + 3, Text::length($code));
    }

    public function test_table_は見出しと行を縦線でつなぐ(): void
    {
        $this->assertSame("| a | b |\n| --- | --- |\n| 1 | 2 |", Markdown::table(['a', 'b'], [['1', '2']]));
    }

    public function test_text_の長さと切り出しは文字単位(): void
    {
        $this->assertSame(3, Text::length('あいう'));
        $this->assertSame('あい', Text::cut('あいう', 2));
        $this->assertSame('あいう', Text::cut('あいう', 5));
    }
}

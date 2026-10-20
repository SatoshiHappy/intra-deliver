<?php

namespace Tests\Unit\Support;

use App\Support\InputText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 入力の文字列の変換(App\Support\InputText。docs の 設計/業務データの入れ物/項目と規則/README.md 2 節の前文 = 入口の TrimStrings・ConvertEmptyStringsToNull と
 * 同じ変換)。前後の空白・改行・U+0000(Str::trim の既定の文字集合)を除き、空は null、配列は中まで、文字列でない値はそのまま。DB は使わない。
 */
final class InputTextTest extends TestCase
{
    /**
     * @return array<string, array{mixed, mixed}>
     */
    public static function values(): array
    {
        return [
            '前後の空白' => ['  名前 ', '名前'],
            '前後の改行とタブ' => ["\n\t名前\r\n", '名前'],
            '前後の全角の空白(Unicode の空白)' => ['　名前　', '名前'],
            '前後の U+0000' => ["\0名前\0", '名前'],
            '中の U+0000 は残る' => ["名\0前", "名\0前"],
            '空' => ['', null],
            '空白だけ' => [" \n ", null],
            'null' => [null, null],
            '整数' => [3, 3],
            '真偽' => [false, false],
            '配列は中まで(キーを保つ)' => [['name' => ' a ', 'empty' => ' ', 'rows' => [['values' => ['x' => "\0b"]]]], ['name' => 'a', 'empty' => null, 'rows' => [['values' => ['x' => 'b']]]]],
        ];
    }

    #[DataProvider('values')]
    public function test_normalizes_a_value(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, InputText::normalize($input));
    }
}

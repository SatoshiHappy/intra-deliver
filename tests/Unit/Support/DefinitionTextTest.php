<?php

namespace Tests\Unit\Support;

use App\Support\DefinitionText;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * 定義のコード・名前・説明・並び順の規則と文言(App\Support\DefinitionText。業務データの入れ物とワークフローの定義の業務処理が共有する):
 * 書式・上限とその境界。予約語・一意・入力の変換は呼び手のもの(ここでは見ない)。
 */
final class DefinitionTextTest extends TestCase
{
    #[TestWith(['a'])]
    #[TestWith(['project_plan'])]
    #[TestWith(['a0123456789012345678901234567890123456789012345678'], '50 文字')]
    public function test_accepts_a_well_formed_code(string $code): void
    {
        $this->assertNull(DefinitionText::codeError($code));
    }

    #[TestWith([null, 'コードを入力してください'])]
    #[TestWith(['Project', 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください'])]
    #[TestWith(['1st', 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください'])]
    #[TestWith(['a01234567890123456789012345678901234567890123456789', 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください'], '51 文字')]
    #[TestWith([12, 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください'])]
    #[TestWith(["abc\n", 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください'], '末尾の改行')]
    public function test_rejects_a_malformed_code(mixed $code, string $message): void
    {
        $this->assertSame($message, DefinitionText::codeError($code));
    }

    public function test_the_code_max_is_the_length_the_code_formats_accept(): void
    {
        $this->assertNull(DefinitionText::codeError(str_repeat('a', DefinitionText::CODE_MAX)));
        $this->assertNotNull(DefinitionText::codeError(str_repeat('a', DefinitionText::CODE_MAX + 1)));
        $this->assertSame(['code' => DefinitionText::CODE_MAX, 'name' => 1000, 'description' => 4000], DefinitionText::maxLengths());
    }

    public function test_a_name_is_required_and_at_most_1000_characters(): void
    {
        $this->assertNull(DefinitionText::textError(str_repeat('あ', DefinitionText::NAME_MAX), '名前'));
        $this->assertSame('名前は 1000 文字以内で入力してください', DefinitionText::textError(str_repeat('あ', 1001), '名前'));
        $this->assertSame('名前を入力してください', DefinitionText::textError(null, '名前'));
        $this->assertNull(DefinitionText::textError(null, '名前', isRequired: false));
        $this->assertSame('名前に使用できない文字(U+0000)が含まれています', DefinitionText::textError("名\0前", '名前'));
        $this->assertSame('名前は文字列で入力してください', DefinitionText::textError("\xff", '名前'));
        $this->assertSame('名前は文字列で入力してください', DefinitionText::textError(1, '名前'));
    }

    public function test_a_description_is_optional_and_at_most_4000_characters(): void
    {
        $this->assertNull(DefinitionText::descriptionError(null));
        $this->assertNull(DefinitionText::descriptionError(str_repeat('あ', DefinitionText::DESCRIPTION_MAX)));
        $this->assertSame('説明は 4000 文字以内で入力してください', DefinitionText::descriptionError(str_repeat('あ', 4001)));
    }

    #[TestWith([null, null])]
    #[TestWith([999_999_999_999_999, null])]
    #[TestWith([-999_999_999_999_999, null])]
    #[TestWith([1_000_000_000_000_000, '並び順は 15 桁までの整数で入力してください'])]
    #[TestWith([-1_000_000_000_000_000, '並び順は 15 桁までの整数で入力してください'])]
    #[TestWith(['10', '並び順は 15 桁までの整数で入力してください'])]
    public function test_a_sort_key_is_an_integer_of_at_most_15_digits(mixed $sortKey, ?string $message): void
    {
        $this->assertSame($message, DefinitionText::sortKeyError($sortKey));
    }
}

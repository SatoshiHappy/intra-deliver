<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\InstructionPlaceholders;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * 置き換え口の部品の実行の時の当て方(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「守ること」・使う文と組み立て.md 11.6)を、当て方の表の
 * 行ごとに通す。
 */
final class InstructionPlaceholdersTest extends TestCase
{
    /** 渡した値の鍵にある口は、値を入れないで済む口でも入れる */
    public function test_a_given_value_is_put_in_whether_the_placeholder_is_optional_or_not(): void
    {
        $text = 'Hello {{name}}.';

        $this->assertSame('Hello Ann.', InstructionPlaceholders::fill('t', $text, ['name' => 'Ann']));
        $this->assertSame('Hello Ann.', InstructionPlaceholders::fill('t', $text, ['name' => 'Ann'], ['name']));
    }

    public function test_an_optional_placeholder_without_a_value_is_kept(): void
    {
        $text = 'Use {{task_tool_name}}.';

        $this->assertSame($text, InstructionPlaceholders::fill('t', $text, [], ['task_tool_name']));
    }

    public function test_a_placeholder_without_a_value_is_a_build_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('指示の文 t に値の無い置き換え口がある: tool_name');

        InstructionPlaceholders::fill('t', 'The tool {{tool_name}} failed: {{error}}', ['error' => 'x']);
    }

    /** 入れた値の中の口は置き換えず、確かめない(値の無い口が値の中にだけあっても誤りにしない) */
    public function test_placeholders_inside_a_value_are_not_replaced(): void
    {
        $text = InstructionPlaceholders::fill('t', '{{a}} / {{b}}', ['a' => '{{b}} {{missing}}', 'b' => 'B']);

        $this->assertSame('{{b}} {{missing}} / B', $text);
    }

    public function test_a_list_value_is_joined_with_new_lines(): void
    {
        $this->assertSame("Files:\none.xlsx\ntwo.pdf", InstructionPlaceholders::fill('t', "Files:\n{{created_files}}", [
            'created_files' => ['one.xlsx', 'two.pdf'],
        ]));
    }

    public function test_names_in_a_text_are_listed_once_in_order(): void
    {
        $text = '{{language}} {{nope}} {{language}} {{skip_me}}';

        $this->assertSame(['language', 'nope', 'skip_me'], InstructionPlaceholders::namesIn($text));
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\InstructionPlaceholders;
use App\Enums\Ai\AiInstructionCall;
use App\Enums\Ai\AiInstructionTemplate;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * 指示の組み立ての口の約束のうち、置き換え口の当て方の分(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「守ること」と「約束のテスト」・
 * 使う文と組み立て.md 11.6 の約束のテスト)。{@see InstructionBuilderContract} が使い、試す版と試す値はそちらの物を使う(このトレイトを直に使わない)。
 */
trait InstructionPlaceholderContract
{
    /**
     * 置き換え口の形を含まない値で組んだ文(すべての呼び出しの種類のシステムの文と送る履歴の文・すべての定型(引数の誤りは問題 1 つと 2 つ)・
     * 要約の後に続ける文)に、置き換え口が残らない(値を入れないで済む口の残る文を版が返さない = docs の 設計/AI/設定とプロンプト/README.md 11.4 の
     * 約束のテスト)。呼び出しの値は、全部が在る組と、無いときの形を持つ値(表示名・知識の期限・定義の指示文・道具)を 1 つずつ無くした組で組む。
     */
    public function test_no_placeholder_is_left_in_the_built_texts(): void
    {
        $builder = $this->instructionBuilder();
        $texts = ['compact_summary' => $builder->compactSummary('Asked for overdue deals')];

        foreach (AiInstructionCall::cases() as $call) {
            $requests = [
                'all' => self::request($call),
                'no_display_name' => self::request($call, displayName: null),
                'no_cutoff' => self::request($call, cutoff: null),
                'no_instructions' => self::request($call, instructions: ''),
                'no_tools' => self::request($call, toolNames: []),
            ];

            foreach ($requests as $form => $request) {
                $built = $builder->build($request);
                $texts["{$call->value}:{$form}:system"] = $built->system;

                foreach ($built->history as $index => $text) {
                    $texts["{$call->value}:{$form}:history:{$index}"] = $text->text;
                }
            }
        }

        foreach (AiInstructionTemplate::cases() as $template) {
            $texts[$template->value] = $builder->template($template, self::templateValues());
        }

        $texts['tool_input_invalid:2'] = $builder->template(AiInstructionTemplate::ToolInputInvalid, [
            ...self::templateValues(),
            'problems' => ['The module field is required.', 'The limit field must be at most 50.'],
        ]);
        $names = array_map(InstructionPlaceholders::namesIn(...), $texts);

        $this->assertSame([], array_filter($names, fn (array $left): bool => $left !== []));
    }

    /** 外から来る値に置き換え口の形の文字があっても、置き換えずにそのまま残す(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.6 の約束のテスト) */
    public function test_placeholders_inside_outside_values_are_kept_as_they_are(): void
    {
        $outside = 'text {{x}} and {{language}}';
        $builder = $this->instructionBuilder();
        $templateWith = fn (AiInstructionTemplate $template, string $key): string => $builder->template(
            $template,
            [...self::templateValues(), $key => $key === 'problems' ? [$outside] : $outside],
        );

        $step = self::request(AiInstructionCall::ConversationStep, instructions: $outside);
        $texts = [
            'output_style_prompt' => $this->systemOf($step),
            'user_feedback' => $templateWith(AiInstructionTemplate::ToolUseRejectedWithReason, 'user_feedback'),
            'summary' => $builder->compactSummary($outside),
            'preview' => $templateWith(AiInstructionTemplate::LargeResultRead, 'preview'),
            'denial_detail' => $templateWith(AiInstructionTemplate::RuleDenied, 'denial_detail'),
            'problems' => $templateWith(AiInstructionTemplate::ToolInputInvalid, 'problems'),
        ];

        foreach ($texts as $key => $text) {
            $this->assertStringContainsString($outside, $text, $key);
        }
    }

    /**
     * 引数の誤りは、値が道具の名前と問題の文の並びだけで文が返り、渡した問題の文がそのまま渡した順に入る(問題の数で言い方を選ぶのは版。
     * docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.6 の problems の行)。問題の文は、渡すほかの値と定型の固定の部分に出ず、
     * 互いに一部にならない見分けの値(位置を取り違えない)。
     *
     * @param  list<string>  $problems
     */
    #[TestWith([['problem-marker-a']])]
    #[TestWith([['problem-marker-a', 'problem-marker-b']])]
    public function test_invalid_input_contains_every_problem_as_it_is_in_the_given_order(array $problems): void
    {
        $text = $this->instructionBuilder()->template(AiInstructionTemplate::ToolInputInvalid, [
            'tool_name' => 'search_records',
            'problems' => $problems,
        ]);
        $positions = array_map(fn (string $problem): int|false => strpos($text, $problem), $problems);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }
}

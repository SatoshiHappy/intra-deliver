<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Enums\Ai\AiInstructionCall;
use Tests\TestCase;

/**
 * 指示の組み立ての口の約束({@see InstructionBuilderContract})を代わりの版 {@see DefaultInstructionBuilder} で流す(公開の写しの CI もこの版で流す =
 * docs の 設計/AI/データと失敗と検査.md 8 節)。加えて、代わりの版だけの振る舞い(要約の答えの整えは前後の空白を除くだけ・アプリの名前を入れる)。
 */
final class DefaultInstructionBuilderTest extends TestCase
{
    use InstructionBuilderContract;

    protected function instructionBuilder(): InstructionBuilder
    {
        return new DefaultInstructionBuilder;
    }

    public function test_the_compact_summary_only_trims_the_answer(): void
    {
        $text = $this->instructionBuilder()->compactSummary("\n  Draft: kept as it is.\nThe summary.  \n");

        $this->assertStringContainsString("Draft: kept as it is.\nThe summary.", $text);
        $this->assertStringNotContainsString('  Draft', $text);
        $this->assertStringNotContainsString("The summary.  \n", $text);
    }

    /**
     * 定義の指示文が空なら出力の形の部分を見出しごと捨てる(代わりの版の条件。移した版は docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5
     * のとおり空でも出力の形を入れるので、約束に入れない)。
     */
    public function test_empty_instructions_drop_the_whole_output_style_part(): void
    {
        $instructions = 'Answer as the sales desk.';
        $call = AiInstructionCall::ConversationStep;
        /** @var array<string, string> $texts */
        $texts = require resource_path(DefaultInstructionBuilder::PATH);
        $part = $texts[DefaultInstructionBuilder::OUTPUT_STYLE];
        $placeholder = '{{output_style_prompt}}';

        $this->assertStringContainsString(
            str_replace($placeholder, $instructions, $part),
            $this->systemOf(self::request($call, instructions: $instructions)),
        );
        $this->assertStringNotContainsString(
            trim(str_replace($placeholder, '', $part)),
            $this->systemOf(self::request($call, instructions: '')),
        );
    }

    public function test_the_app_name_is_put_in_as_the_agent_name(): void
    {
        config(['app.name' => 'Intra Test App']);

        $system = $this->instructionBuilder()->build(self::request(AiInstructionCall::ConversationStep))->system;

        $this->assertStringContainsString('Intra Test App', $system);
    }
}

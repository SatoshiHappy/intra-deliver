<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Instructions\DefaultInstructionBuilder;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Actions\Ai\Instructions\InstructionPlaceholders;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use App\Enums\Ai\AiInstructionCall;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * 指示の組み立ての口の約束({@see InstructionBuilderContract})を代わりの版 {@see DefaultInstructionBuilder} で流す(公開の写しの CI もこの版で流す =
 * docs の 設計/AI/データと失敗と検査.md 8 節)。加えて、代わりの版だけの振る舞い(要約の答えの整えは前後の空白を除くだけ・アプリの名前を入れる・
 * 表示名の有無で環境の文を選ぶ・要約の予備の履歴は要約の指示だけ)。
 */
final class DefaultInstructionBuilderTest extends TestCase
{
    use InstructionBuilderContract;

    protected function instructionBuilder(): InstructionBuilder
    {
        return new DefaultInstructionBuilder;
    }

    /** 代わりの版の会話の印 = 組んだ文の中の、行の全体が角かっこで囲まれた行 */
    protected function conversationMarkerPattern(): string
    {
        return '/^\[[^\[\]\r\n]+\]$/mu';
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
        $part = self::defaultTexts()[DefaultInstructionBuilder::OUTPUT_STYLE];
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

    /** 表示名があれば環境の文に表示名と ID を入れる */
    public function test_with_a_display_name_the_environment_text_is_used(): void
    {
        $text = self::defaultTexts()[DefaultInstructionBuilder::ENVIRONMENT];
        $environment = InstructionPlaceholders::fill('t', $text, [
            'model_marketing_name' => 'Model Display X',
            'model_id' => 'model-id-x',
        ]);

        $system = $this->systemOf(self::request(AiInstructionCall::ConversationStep, displayName: 'Model Display X'));

        $this->assertStringContainsString($environment, $system);
    }

    /**
     * 表示名が無ければ表示名の無い環境の文を使い、表示名の口を持つ環境の文は入らない(表示名の代わりに ID を写して今の環境の文を使う形も
     * 入らない)。
     */
    #[TestWith([AiInstructionCall::ConversationStep])]
    #[TestWith([AiInstructionCall::CreateFile])]
    public function test_without_a_display_name_the_environment_text_without_it_is_used(AiInstructionCall $call): void
    {
        $texts = self::defaultTexts();
        $withoutName = $texts[DefaultInstructionBuilder::ENVIRONMENT_WITHOUT_DISPLAY_NAME];
        $without = InstructionPlaceholders::fill('t', $withoutName, ['model_id' => 'model-id-x']);
        $copied = InstructionPlaceholders::fill('t', $texts[DefaultInstructionBuilder::ENVIRONMENT], [
            'model_marketing_name' => 'model-id-x',
            'model_id' => 'model-id-x',
        ]);

        $system = $this->systemOf(self::request($call, displayName: null));

        $this->assertStringContainsString($without, $system);
        $this->assertStringNotContainsString($copied, $system);
    }

    /** 要約の予備の送る履歴は、末尾の要約の指示の 1 つだけ(日付の文を足さない。要約の送る履歴の末尾の文と同じ) */
    public function test_the_compaction_fallback_history_is_only_the_compaction_instruction(): void
    {
        $compaction = $this->instructionBuilder()->build(self::request(AiInstructionCall::Compaction))->history;
        $last = end($compaction);
        $custom = (new SharedInstructionTemplates)->compactionCustomInstructions();

        $history = $this->instructionBuilder()->build(self::request(AiInstructionCall::CompactionFallback))->history;

        $this->assertCount(1, $history);
        $this->assertFalse($history[0]->atStart);
        $this->assertNotFalse($last);
        $this->assertSame($last->text, $history[0]->text);
        $this->assertStringContainsString($custom, $history[0]->text);
    }

    /**
     * 代わりの版の既定の文。
     *
     * @return array<string, string>
     */
    private static function defaultTexts(): array
    {
        /** @var array<string, string> $texts */
        $texts = require resource_path(DefaultInstructionBuilder::PATH);

        return $texts;
    }
}

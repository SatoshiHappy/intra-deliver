<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の型の実行の原文とエージェントの定義の表の制約(docs の 設計/AI/実行の記録.md 2.2、定義 = 設計/AI/設定とプロンプト/README.md 11.1)と、
 * 実行の終わりの状態(設計/AI/エージェントの実行/途中の表示と上限.md 9.11): 会話の型の原文はシステムの文を持たず、答えの実行は利用者の文も持たず、
 * 途中の文は配列。定義の名前は空でなく、道具とナレッジは配列。終わり = 成功・失敗・確認待ち・止めた。
 */
final class ConversationTextsAndDefinitionsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** 会話の型の原文: システムの文を持たず、答えの実行は利用者の文も持たない。途中の文はステップの番号と文の列 */
    public function test_the_texts_of_a_conversation_run(): void
    {
        $partial = [['step' => 1, 'text' => '探しています'], ['step' => 2, 'text' => '見つかりました']];
        $sent = $this->text(['user_text' => '会議を探して', 'partial_text' => $partial]);
        $answered = $this->text([]);

        $this->assertSame([null, '会議を探して', $partial], [$sent->system_text, $sent->user_text, $sent->partial_text]);
        $this->assertSame([null, null, null], [$answered->system_text, $answered->user_text, $answered->partial_text]);
        $this->assertRejectedBy(
            'ai_run_texts_partial_text_check',
            fn () => $this->text(['partial_text' => ['step' => 1]]),
        );
    }

    public function test_the_columns_of_an_agent_definition(): void
    {
        $lists = ['tools' => ['search_records'], 'knowledge' => ['meetings']];
        $definition = AiAgentDefinition::factory()->create($lists);
        $definition->refresh();
        $values = [$definition->instructions, $definition->tools, $definition->knowledge];
        $this->assertSame(['', ['search_records'], ['meetings']], $values);

        $this->assertRejectedBy(
            'ai_agent_definitions_name_check',
            fn () => AiAgentDefinition::factory()->create(['name' => '']),
        );
        $objectTools = ['tools' => ['name' => 'search_records']];
        $this->assertRejectedBy(
            'ai_agent_definitions_tools_check',
            fn () => AiAgentDefinition::factory()->create($objectTools),
        );
        $this->assertRejectedBy(
            'ai_agent_definitions_knowledge_check',
            fn () => $this->insertDefinition(['knowledge' => '"meetings"']),
        );
        $this->assertRejectedBy(
            'ai_agent_definitions_tools_check',
            fn () => $this->insertDefinition(['tools' => '{}']),
        );
    }

    /** 終わり = 成功・失敗・確認待ち・止めた。待ち・処理中は終わっていない */
    public function test_the_finished_statuses(): void
    {
        $finished = [];

        foreach (AiRunStatus::cases() as $status) {
            $finished[$status->value] = $status->isFinished();
        }

        $expected = [
            'queued' => false,
            'running' => false,
            'succeeded' => true,
            'failed' => true,
            'awaiting_confirmation' => true,
            'stopped' => true,
        ];
        $this->assertSame($expected, $finished);
    }

    /**
     * 新しい会話の型の実行の原文(既定は 2 つの文と途中の文が無い)。
     *
     * @param  array<string, mixed>  $values
     */
    private function text(array $values): AiRunText
    {
        $runId = AiRun::factory()->conversation()->create()->id;
        $text = ['ai_run_id' => $runId, 'system_text' => null, 'user_text' => null, ...$values];

        return AiRunText::query()->create($text)->refresh();
    }

    /**
     * 定義の行を表に直に足す(jsonb の値を JSON の文字列で置く)。
     *
     * @param  array<string, string>  $values
     */
    private function insertDefinition(array $values): void
    {
        DB::table('ai_agent_definitions')->insert([
            'name' => '定義',
            'instructions' => '',
            'tools' => '[]',
            'knowledge' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
            ...$values,
        ]);
    }
}

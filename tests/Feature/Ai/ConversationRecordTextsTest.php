<?php

namespace Tests\Feature\Ai;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiRunStepText;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ステップと道具の行の原文の表(docs の 設計/AI/実行の記録.md 2.2・2.4、消す対象 = 設計/AI/データと失敗と検査.md 6 節): 親の行と 1 対 1 で書け、
 * 親の行の削除で消える。実行を消すと子の行と原文が全部消える。元の引数は object でない値も書け、確認の内容は object、表示の名前は配列。
 */
final class ConversationRecordTextsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_the_texts_of_a_step_and_a_tool_call_are_written_and_read(): void
    {
        $step = AiRunStep::factory()->withText()->create();
        $records = [['module' => 'meetings', 'id' => 7]];
        $call = AiToolCall::factory()->inStep($step)->withText()->create(['records' => $records]);
        $text = $call->text()->sole();
        $text->update(['confirmation' => ['target' => 'meetings/7', 'current' => 'a', 'new' => 'b'], 'record_names' => [
            ['module' => 'meetings', 'id' => 7, 'name' => '定例会'],
        ]]);

        $this->assertSame(['query' => '会議'], $text->refresh()->arguments);
        $this->assertSame([7, '定例会'], [$call->records[0]['id'], $text->record_names[0]['name']]);
        $this->assertSame('b', $text->confirmation['new'] ?? null);
        $this->assertStringContainsString('"role":"user"', (string) $step->text?->sent_text);
        $this->assertNull($step->text?->output_text);
        $noSentText = fn () => AiRunStepText::factory()->create(['sent_text' => null]);
        $this->assertRejectedAsNull('ai_run_step_texts', 'sent_text', $noSentText);
        $callId = AiToolCall::factory()->create()->id;
        $noArguments = ['ai_tool_call_id' => $callId, 'record_names' => '[]', 'created_at' => now()];
        $insert = fn () => DB::table('ai_tool_call_texts')->insert($noArguments);
        $this->assertRejectedAsNull('ai_tool_call_texts', 'arguments', $insert);
    }

    /** 元の引数は形の確かめに落ちた値も残す(型の制約が無い)。確認の内容と表示の名前は形を持つ */
    public function test_the_arguments_keep_any_json_and_the_other_json_columns_have_their_forms(): void
    {
        foreach ([[], ['a', 'b'], 'not an object', 3] as $arguments) {
            $text = AiToolCallText::factory()->create(['arguments' => $arguments])->refresh();
            $this->assertSame($arguments, $text->arguments);
        }

        $this->assertRejectedBy(
            'ai_tool_call_texts_confirmation_check',
            fn () => AiToolCallText::factory()->create(['confirmation' => ['a', 'b']]),
        );
        $this->assertRejectedBy(
            'ai_tool_call_texts_record_names_check',
            fn () => AiToolCallText::factory()->create(['record_names' => ['a' => 1]]),
        );
    }

    public function test_deleting_a_parent_row_deletes_its_texts(): void
    {
        $step = AiRunStep::factory()->withText()->create();
        $call = AiToolCall::factory()->inStep($step)->withText()->create();
        $otherCall = AiToolCall::factory()->withText()->create();

        DB::table('ai_tool_calls')->where('id', $otherCall->id)->delete();
        $this->assertSame(0, AiToolCallText::query()->whereKey($otherCall->id)->count());

        DB::table('ai_run_steps')->where('id', $step->id)->delete();
        $this->assertSame(0, AiRunStepText::query()->whereKey($step->id)->count());
        $this->assertSame(0, AiToolCall::query()->whereKey($call->id)->count());
        $this->assertSame(0, AiToolCallText::query()->whereKey($call->id)->count());
    }

    /** 実行を消すと、送り直しと中で呼んだ道具の行を指し合う子の行も、原文も、全部消える */
    public function test_deleting_a_run_deletes_all_its_child_rows_and_texts(): void
    {
        $run = AiRun::factory()->conversation()->create();
        $step = AiRunStep::factory()->withText()->create(['ai_run_id' => $run->id]);
        $call = AiToolCall::factory()->inStep($step)->withText()->create();
        $inside = AiRunStep::factory()->insideToolCall($call)->withText()->create();
        AiRunStep::factory()->retryOf($inside)->insideToolCall($call)->withText()->create();
        AiToolCall::factory()->inStep($inside)->asked()->withText()->create();

        DB::table('ai_runs')->where('id', $run->id)->delete();

        $this->assertSame([0, 0], [AiRunStep::query()->count(), AiRunStepText::query()->count()]);
        $this->assertSame([0, 0], [AiToolCall::query()->count(), AiToolCallText::query()->count()]);
    }
}

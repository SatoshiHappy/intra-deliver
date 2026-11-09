<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiToolCallAnswer;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiToolCall;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 実行の子の表の起きない組み合わせ(docs の 設計/AI/実行の記録.md 2.3・2.4): ステップは会話の型の実行だけが持ち、子どうしは同じ実行の行だけを指す。
 * 実行の中の番号・呼び出しの id、ステップの中の順は重ならない。答えを書いた実行は、この行の実行に続く実行だけ(前の実行の一意 = ADR 0040 の決定 14)。
 */
final class ConversationChildRowsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use BuildsConversationSteps;

    public function test_only_a_conversation_run_has_steps(): void
    {
        $prompt = AiRun::factory()->create();
        $transcription = AiRun::factory()->create([
            'audio_seconds' => '60.000',
            'original_input_upload_id' => 1,
            'max_output_tokens' => null,
        ]);

        foreach ([$prompt, $transcription] as $run) {
            $this->assertRejectedBy(
                'ai_run_steps_ai_run_id_run_kind_type_foreign',
                fn () => $this->step(['ai_run_id' => $run->id]),
            );
        }

        $conversation = AiRun::factory()->conversation()->create();
        $step = $this->step(['ai_run_id' => $conversation->id]);
        $this->assertSame([1], $step->aiRun?->steps()->pluck('sequence')->all());
    }

    public function test_the_rows_of_a_run_point_only_to_rows_of_the_same_run(): void
    {
        $otherStep = $this->step();
        $otherToolCall = AiToolCall::factory()->inStep($otherStep)->create();
        $run = AiRun::factory()->conversation()->create();

        $retry = ['ai_run_id' => $run->id, 'retried_from_step_id' => $otherStep->id, 'retry_attempt' => 1];
        $this->assertRejectedBy('ai_run_steps_ai_run_id_retried_from_step_id_foreign', fn () => $this->step($retry));
        $createFile = ['ai_run_id' => $run->id, 'send_kind' => AiRunStepSendKind::CreateFile];
        $this->assertRejectedBy(
            'ai_run_steps_ai_run_id_ai_tool_call_id_foreign',
            fn () => $this->step([...$createFile, 'ai_tool_call_id' => $otherToolCall->id]),
        );
        $this->assertRejectedBy(
            'ai_tool_calls_ai_run_id_ai_run_step_id_foreign',
            fn () => AiToolCall::factory()->create(['ai_run_step_id' => $otherStep->id, 'ai_run_id' => $run->id]),
        );
        $this->assertSame([$otherToolCall->id], $otherStep->aiRun?->toolCalls()->pluck('id')->all());
    }

    public function test_the_sequence_the_call_id_and_the_position_do_not_overlap(): void
    {
        $step = $this->step();
        $this->assertRejectedBy(
            'ai_run_steps_ai_run_id_sequence_unique',
            fn () => $this->step(['ai_run_id' => $step->ai_run_id, 'sequence' => $step->sequence]),
        );
        $call = AiToolCall::factory()->inStep($step)->create();
        $nextStep = $this->step(['ai_run_id' => $step->ai_run_id]);
        $this->assertRejectedBy(
            'ai_tool_calls_ai_run_id_call_id_unique',
            fn () => AiToolCall::factory()->inStep($nextStep)->create(['call_id' => $call->call_id]),
        );
        $this->assertRejectedBy(
            'ai_tool_calls_ai_run_step_id_position_unique',
            fn () => AiToolCall::factory()->inStep($step)->create(['position' => $call->position]),
        );

        $otherRun = AiToolCall::factory()->create(['call_id' => $call->call_id]);
        $next = AiToolCall::factory()->inStep($step)->create();
        $this->assertSame([$call->call_id, 2], [$otherRun->call_id, $next->position]);
    }

    public function test_only_the_run_that_follows_the_run_of_the_row_writes_the_answer(): void
    {
        $call = AiToolCall::factory()->answered(AiToolCallAnswer::Approve)->create()->refresh();
        $this->assertSame($call->ai_run_id, $call->answerRun?->previous_run_id);
        $foreign = 'ai_tool_calls_ai_run_id_answer_run_id_foreign';
        $notFollowing = AiRun::factory()->conversation()->create();

        foreach ([$notFollowing->id, $call->ai_run_id] as $runId) {
            $this->assertRejectedBy(
                $foreign,
                fn () => $this->updateRow('ai_tool_calls', $call->id, ['answer_run_id' => $runId]),
            );
        }

        $nextText = AiToolCall::factory()->answered(AiToolCallAnswer::NextText)->create()->refresh();
        $textRun = $nextText->answerRun;
        $this->assertSame([null, $nextText->ai_run_id], [$textRun?->answer, $textRun?->previous_run_id]);
        $this->assertRejectedBy($foreign, fn () => DB::table('ai_runs')->where('id', $call->answer_run_id)->delete());
    }

    /** 同じ実行の問うの呼び出しの答えは、どれも同じ続く実行が書く(1 つの実行に続く実行は 1 つ)。答えの違う組は作りの誤り */
    public function test_the_answers_in_one_run_share_the_following_run(): void
    {
        $step = $this->step();
        $answered = AiToolCall::factory()->inStep($step)->answered(AiToolCallAnswer::Approve);

        $first = $answered->createOne();
        $second = $answered->createOne();

        $this->assertSame($first->answer_run_id, $second->answer_run_id);
        $this->assertSame(1, AiRun::query()->where('previous_run_id', $step->ai_run_id)->count());

        $this->expectException(LogicException::class);
        AiToolCall::factory()->inStep($step)->answered(AiToolCallAnswer::NextText)->createOne();
    }
}

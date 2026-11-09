<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiConversationPurpose;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiToolCallAnswer;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use App\Queries\Ai\ConversationSettings;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの行の表の制約(docs の 設計/AI/実行の記録.md 2.3): 送信の種別ごとの用途と中で呼んだ道具の行、値の一覧、送り直しの 2 列、使用量の組と
 * 内訳と非負、コード実行の時間。状態ごとの列の有無 = ConversationStepStatusTest、子の表の起きない組み合わせ = ConversationChildRowsTest。
 * 1 つの書き込みが 2 つ以上の制約に当たらないように行を作る(PostgreSQL は検査制約を名前の順に確かめる)。
 */
final class ConversationStepTablesTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use BuildsConversationSteps;

    private const SEND_KIND_PURPOSE = 'ai_run_steps_send_kind_purpose_check';

    /** 会話・題の用途の種別は中で呼んだ道具の行を持たず、ほかの用途では断られる */
    public function test_the_send_kinds_without_a_tool_call_take_their_purpose(): void
    {
        $kinds = [
            AiRunStepSendKind::Turn, AiRunStepSendKind::Continuation, AiRunStepSendKind::Compaction,
            AiRunStepSendKind::CompactionFallback, AiRunStepSendKind::Title,
        ];
        $toolCall = AiToolCall::factory()->create();

        foreach ($kinds as $kind) {
            $title = $kind === AiRunStepSendKind::Title;
            $purpose = $title ? AiConversationPurpose::Title : AiConversationPurpose::Conversation;
            $other = $title ? AiConversationPurpose::Conversation : AiConversationPurpose::Title;
            $row = ['ai_run_id' => $toolCall->ai_run_id, 'send_kind' => $kind];
            $this->assertSame($purpose, $this->step($row)->refresh()->purpose);
            $this->assertRejectedBy(self::SEND_KIND_PURPOSE, fn () => $this->step([...$row, 'purpose' => $other]));
            $withToolCall = [...$row, 'ai_tool_call_id' => $toolCall->id];
            $this->assertRejectedBy(self::SEND_KIND_PURPOSE, fn () => $this->step($withToolCall));
        }
    }

    /** ファイルを作るの種別は中で呼んだ道具の行を持ち、ほかの用途では断られる */
    public function test_the_create_file_send_kinds_need_the_calling_tool_call(): void
    {
        $toolCall = AiToolCall::factory()->create();

        foreach ([AiRunStepSendKind::CreateFile, AiRunStepSendKind::WorkspaceContinuation] as $kind) {
            $inside = AiRunStep::factory()->insideToolCall($toolCall, $kind);
            $step = $inside->create()->refresh();
            $expected = [AiConversationPurpose::CreateFile, $toolCall->id];
            $this->assertSame($expected, [$step->purpose, $step->ai_tool_call_id]);
            $this->assertSame($toolCall->id, $step->callingToolCall?->id);
            $conversation = ['purpose' => AiConversationPurpose::Conversation];
            $this->assertRejectedBy(self::SEND_KIND_PURPOSE, fn () => $inside->create($conversation));
            $withoutToolCall = ['ai_run_id' => $toolCall->ai_run_id, 'send_kind' => $kind];
            $this->assertRejectedBy(self::SEND_KIND_PURPOSE, fn () => $this->step($withoutToolCall));
        }
    }

    public function test_values_out_of_the_lists_are_rejected(): void
    {
        $stepId = $this->step()->id;
        $values = [
            'ai_run_steps_purpose_check' => ['purpose' => 'summary'],
            'ai_run_steps_send_kind_check' => ['send_kind' => 'retry'],
            'ai_run_steps_status_check' => ['status' => 'paused'],
            'ai_run_steps_run_kind_type_check' => ['run_kind_type' => 'prompt'],
            'ai_run_steps_sequence_check' => ['sequence' => 0],
        ];

        foreach ($values as $check => $value) {
            $this->assertRejectedBy($check, fn () => $this->updateRow('ai_run_steps', $stepId, $value));
        }

        $failedId = AiRunStep::factory()->failed()->create()->id;
        $unknown = ['error_code' => 'unknown'];
        $this->assertRejectedBy(
            'ai_run_steps_error_code_check',
            fn () => $this->updateRow('ai_run_steps', $failedId, $unknown),
        );

        foreach (AiRunErrorCode::cases() as $code) {
            $this->assertSame($code, AiRunStep::factory()->failed($code)->create()->refresh()->error_code);
        }
    }

    /** 送り直しは何回目か(1 以上)と送り直した元を両方持つか、両方持たない */
    public function test_the_retry_attempt_and_the_retried_step_go_together(): void
    {
        $original = $this->step(['ai_run_id' => AiRun::factory()->conversation()->create()->id]);
        $check = 'ai_run_steps_retry_check';
        $onlyAttempt = ['ai_run_id' => $original->ai_run_id, 'retry_attempt' => 1];
        $this->assertRejectedBy($check, fn () => $this->step($onlyAttempt));
        $onlyFrom = ['ai_run_id' => $original->ai_run_id, 'retried_from_step_id' => $original->id];
        $this->assertRejectedBy($check, fn () => $this->step($onlyFrom));
        $this->assertRejectedBy($check, fn () => AiRunStep::factory()->retryOf($original, 0)->create());

        $retry = AiRunStep::factory()->retryOf($original, 2)->create()->refresh();
        $this->assertSame([2, $original->id], [$retry->retry_attempt, $retry->retriedFrom?->id]);
    }

    /** 使用量・単価・費用はすべて無いかすべてある。内訳は使用量があるときだけ、トークン・費用・見積もりは負でない */
    public function test_the_usage_pricing_and_cost_go_together_and_are_not_negative(): void
    {
        $usage = ['input_tokens' => 10, 'output_tokens' => 5, 'pricing' => ['input' => 3.0], 'cost_usd' => '0.000100'];
        $failed = fn (array $values): AiRunStep => AiRunStep::factory()->failed()->create($values);

        foreach (array_keys($usage) as $missing) {
            $partial = array_diff_key($usage, [$missing => true]);
            $this->assertRejectedBy('ai_run_steps_usage_check', fn () => $failed($partial));
        }

        foreach (['cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'] as $column) {
            $this->assertRejectedBy('ai_run_steps_usage_breakdown_check', fn () => $failed([$column => 1]));
            $this->assertRejectedBy('ai_run_steps_tokens_check', fn () => $failed([...$usage, $column => -1]));
            $this->assertSame(1, $failed([...$usage, $column => 1])->refresh()->{$column});
        }

        $this->assertRejectedBy('ai_run_steps_tokens_check', fn () => $failed([...$usage, 'input_tokens' => -1]));
        $this->assertRejectedBy('ai_run_steps_pricing_check', fn () => $failed([...$usage, 'pricing' => [3.0]]));
        $negative = '-0.000001';
        $this->assertRejectedBy('ai_run_steps_cost_usd_check', fn () => $failed([...$usage, 'cost_usd' => $negative]));
        $reserved = 'ai_run_steps_reserved_cost_usd_check';
        $this->assertRejectedBy($reserved, fn () => $this->step(['reserved_cost_usd' => $negative]));
        $this->assertSame('0.000100', $failed($usage)->refresh()->cost_usd);
        $this->assertNull($failed([])->refresh()->cost_usd);
    }

    public function test_the_code_execution_seconds_are_not_negative(): void
    {
        $inside = AiRunStep::factory()->insideToolCall(AiToolCall::factory()->create())->succeeded();

        $this->assertRejectedBy(
            'ai_run_steps_code_execution_seconds_check',
            fn () => $inside->create(['code_execution_seconds' => '-0.001']),
        );
        $zero = $inside->create(['code_execution_seconds' => '0'])->refresh();
        $this->assertSame('0.000', $zero->code_execution_seconds);
    }

    /** 用途の名前の正本は列挙 1 つ。確認の答えの実行・やめるは、答えの実行の答えと同じ値 */
    public function test_the_purpose_names_and_the_answer_values_come_from_one_place(): void
    {
        $this->assertSame(array_column(AiConversationPurpose::cases(), 'value'), ConversationSettings::PURPOSES);
        $this->assertSame(
            array_column(AiConversationAnswer::cases(), 'value'),
            [AiToolCallAnswer::Approve->value, AiToolCallAnswer::Reject->value],
        );
    }
}

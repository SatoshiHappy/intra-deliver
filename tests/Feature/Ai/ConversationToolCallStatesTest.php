<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiToolCall;
use Database\Factories\Ai\AiToolCallFactory;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 道具の行の状態と、ほかの列の制約(docs の 設計/AI/実行の記録.md 2.4 の道具の行の状態の表と起きない組み合わせ)。
 * 表の行ごとに、許す状態のそれぞれが通り、許さない状態が断られることを通す。
 */
final class ConversationToolCallStatesTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use BuildsConversationSteps;

    private const STATES = 'ai_tool_calls_status_of_decision_answer_check';

    /** T1〜T4(答えの無い、問う以外の判定) */
    public function test_each_decision_without_an_answer_allows_its_states(): void
    {
        $this->assertStates(AiToolCall::factory()->notReached(), [AiToolCallStatus::Cancelled]);
        $this->assertRejectedBy(self::STATES, fn () => AiToolCall::factory()->notReached()->create([
            'status' => AiToolCallStatus::Running,
            'finished_at' => null,
        ]));

        $wrapper = [
            AiToolCallDecision::UnknownTool, AiToolCallDecision::InvalidInput, AiToolCallDecision::ValidationError,
        ];

        foreach ($wrapper as $decision) {
            $this->assertStates(AiToolCall::factory()->wrapperError($decision), [AiToolCallStatus::ToolError]);
        }

        $this->assertStates(AiToolCall::factory()->denied(), [AiToolCallStatus::Rejected]);
        $this->assertStates(AiToolCall::factory()->allowed(), [
            AiToolCallStatus::Done, AiToolCallStatus::ToolError, AiToolCallStatus::Running, AiToolCallStatus::Unknown,
        ]);
    }

    /** T5〜T9(問う。答えの有無と値ごと) */
    public function test_each_answer_to_an_ask_allows_its_states(): void
    {
        $asked = [AiToolCallStatus::AwaitingConfirmation, AiToolCallStatus::Cancelled];
        $this->assertStates(AiToolCall::factory()->asked(), $asked);
        $this->assertStates(AiToolCall::factory()->answered(AiToolCallAnswer::Approve), [
            AiToolCallStatus::Running, AiToolCallStatus::ToolError, AiToolCallStatus::Rejected, AiToolCallStatus::Done,
            AiToolCallStatus::Cancelled, AiToolCallStatus::Unknown,
        ]);

        foreach ([AiToolCallAnswer::Reject, AiToolCallAnswer::NextText] as $answer) {
            $this->assertStates(AiToolCall::factory()->answered($answer), [AiToolCallStatus::Rejected]);
        }
    }

    /** 答えを書くのは問うの呼び出しだけ(起きない組み合わせ) */
    public function test_only_an_ask_has_an_answer(): void
    {
        $answered = AiToolCall::factory()->answered(AiToolCallAnswer::Reject);

        foreach ([null, AiToolCallDecision::InvalidInput, AiToolCallDecision::Allow] as $decision) {
            $this->assertRejectedBy(self::STATES, fn () => $answered->create([
                'decision' => $decision,
                'confirmation_version' => null,
            ]));
        }

        $denied = ['decision' => AiToolCallDecision::Deny, 'decision_reason' => 'rule', 'confirmation_version' => null];
        $this->assertRejectedBy(self::STATES, fn () => $answered->create($denied));
    }

    /** 答えと答えを書いた実行は同じ有無。理由は断るときだけ有る */
    public function test_the_answer_run_goes_with_the_answer_and_the_reason_with_a_deny(): void
    {
        $answerRun = 'ai_tool_calls_answer_run_id_check';
        $rejected = AiToolCall::factory()->answered(AiToolCallAnswer::Reject);
        $this->assertRejectedBy($answerRun, fn () => $rejected->create(['answer_run_id' => null]));
        $otherAnswerRun = ['answer_run_id' => $rejected->create()->answer_run_id];
        $this->assertRejectedBy($answerRun, fn () => AiToolCall::factory()->asked()->create($otherAnswerRun));

        $reason = 'ai_tool_calls_decision_reason_check';
        $withReason = ['decision_reason' => 'rule'];
        $this->assertRejectedBy($reason, fn () => AiToolCall::factory()->allowed()->create($withReason));
        $this->assertRejectedBy($reason, fn () => AiToolCall::factory()->denied()->create(['decision_reason' => null]));
        $this->assertRejectedBy($reason, fn () => AiToolCall::factory()->denied('')->create());
        $this->assertSame('rule', AiToolCall::factory()->denied()->create()->refresh()->decision_reason);
    }

    /** 確認の内容の版は問うだけ。終わりの時刻は処理中・確認待ちでだけ無い */
    public function test_the_version_goes_with_an_ask_and_the_end_follows_the_status(): void
    {
        $version = 'ai_tool_calls_confirmation_version_check';
        $withVersion = ['confirmation_version' => 1];
        $this->assertRejectedBy($version, fn () => AiToolCall::factory()->allowed()->create($withVersion));
        $this->assertRejectedBy($version, fn () => AiToolCall::factory()->notReached()->create($withVersion));
        $this->assertSame(1, AiToolCall::factory()->asked()->create()->refresh()->confirmation_version);

        $finished = 'ai_tool_calls_status_finished_at_check';
        $running = AiToolCall::factory()->allowed(AiToolCallStatus::Running);
        $this->assertRejectedBy($finished, fn () => $running->create(['finished_at' => now()]));
        $this->assertRejectedBy($finished, fn () => AiToolCall::factory()->asked()->create(['finished_at' => now()]));
        $this->assertRejectedBy($finished, fn () => AiToolCall::factory()->allowed()->create(['finished_at' => null]));
    }

    public function test_values_out_of_the_lists_are_rejected(): void
    {
        $callId = AiToolCall::factory()->create()->id;
        $answeredId = AiToolCall::factory()->answered(AiToolCallAnswer::Approve)->create()->id;
        $values = [
            'ai_tool_calls_decision_check' => [$callId, ['decision' => 'maybe']],
            'ai_tool_calls_status_check' => [$callId, ['status' => 'paused']],
            'ai_tool_calls_answer_check' => [$answeredId, ['answer' => 'later']],
            'ai_tool_calls_records_check' => [$callId, ['records' => '{"a": 1}']],
            'ai_tool_calls_position_check' => [$callId, ['position' => 0]],
            'ai_tool_calls_call_id_check' => [$callId, ['call_id' => '']],
            'ai_tool_calls_tool_name_check' => [$callId, ['tool_name' => '']],
        ];

        foreach ($values as $check => [$id, $value]) {
            $this->assertRejectedBy($check, fn () => $this->updateRow('ai_tool_calls', $id, $value));
        }

        $records = [['module' => 'meetings', 'id' => 1]];
        $this->assertEquals($records, AiToolCall::factory()->create(['records' => $records])->refresh()->records);
    }

    /**
     * 許す状態のそれぞれで行が書け、許さない状態のどれか 1 つで断られる。
     *
     * @param  list<AiToolCallStatus>  $allowed
     */
    private function assertStates(AiToolCallFactory $factory, array $allowed): void
    {
        $open = [AiToolCallStatus::Running, AiToolCallStatus::AwaitingConfirmation];

        foreach ($allowed as $status) {
            $finishedAt = in_array($status, $open, true) ? null : now();
            $call = $factory->createOne(['status' => $status, 'finished_at' => $finishedAt])->refresh();
            $this->assertSame($status, $call->status);
        }

        $other = array_values(array_diff(
            array_column(AiToolCallStatus::cases(), 'value'),
            array_column($allowed, 'value'),
        ))[0];
        $otherFinishedAt = in_array(AiToolCallStatus::from($other), $open, true) ? null : now();
        $otherRow = ['status' => $other, 'finished_at' => $otherFinishedAt];
        $this->assertRejectedBy(self::STATES, fn () => $factory->create($otherRow));
    }
}

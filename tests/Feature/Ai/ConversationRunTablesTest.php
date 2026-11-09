<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の型の実行の行と原文と定義の表の制約(docs の 設計/AI/実行の記録.md 2.1・2.2、起きない組み合わせ =
 * 設計/AI/エージェントの実行/途中の表示と上限.md 9.11、前の実行の一意 = ADR 0040 の決定 14): 種類の型ごとに持つ列の組、
 * 確認待ち・止めた・設定・確認は会話の型だけ、終わりと開始の時刻、止めた理由と費用の上限の期間、答えと前の実行と題、
 * 会話の型の成功は費用を持たない、前の実行は一意、定義と前の実行は消せない(RESTRICT)。原文と定義の表 = ConversationTextsAndDefinitionsTest。
 * 1 つの書き込みが 2 つ以上の制約に当たらないように行を作る(PostgreSQL は検査制約を名前の順に確かめる)。
 */
final class ConversationRunTablesTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const TYPE_COLUMNS = 'ai_runs_type_columns_check';

    public function test_a_conversation_run_keeps_its_columns_and_has_no_common_provider_or_estimate(): void
    {
        $previous = $this->awaitingRun();
        $run = $this->conversationRun([
            'previous_run_id' => $previous->id,
            'answer' => AiConversationAnswer::Approve,
            ...$this->stopped(AiRunStopReason::Budget),
            'stop_budget_period' => 'day',
            'stop_requested_at' => now(),
            'purposes' => ['conversation' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5']],
            'passed_tools' => ['search_records'],
            'knowledge_modules' => ['meetings'],
            'compacted' => true,
            'retry_wait_seconds' => 0,
            'retry_attempt' => 1,
            'step_reserved_sum_usd' => '0.010000',
            'step_cost_sum_usd' => '0.000000',
            'step_usage_sum' => ['input_tokens' => 10],
        ])->refresh();

        $common = [$run->provider, $run->model, $run->reserved_cost_usd, $run->source_record_ids];
        $this->assertSame([AiKindType::Conversation, [null, null, null, null]], [$run->kind_type, $common]);
        $ending = [$run->answer, $run->stop_reason, $run->stop_budget_period];
        $this->assertSame([AiConversationAnswer::Approve, AiRunStopReason::Budget, 'day'], $ending);
        $this->assertSame(['0.010000', ['meetings']], [$run->step_reserved_sum_usd, $run->knowledge_modules]);
        $this->assertSame($previous->id, $run->previousRun?->id);
        $this->assertSame($run->agent_definition_id, $run->agentDefinition?->id);
    }

    public function test_the_kind_type_decides_the_columns_of_a_run(): void
    {
        $conversationId = $this->conversationRun()->id;
        $promptId = AiRun::factory()->create()->id;
        $this->assertRejectedBy(
            'ai_runs_kind_type_check',
            fn () => $this->updateRun($conversationId, ['kind_type' => 'image']),
        );
        $this->assertRejectedAsNull(
            'ai_runs', 'kind_type',
            fn () => $this->updateRun($promptId, ['kind_type' => null]),
        );
        $notConversation = [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'reserved_cost_usd' => '0.010000',
            'max_output_tokens' => 100,
            'source_record_ids' => [],
            'agent_definition_id' => null,
            'wants_title' => null,
        ];

        foreach ($notConversation as $column => $value) {
            $this->assertRejectedBy(self::TYPE_COLUMNS, fn () => $this->conversationRun([$column => $value]));
        }

        $usage = ['input_tokens' => 1, 'output_tokens' => 1, 'pricing' => ['input' => 1], 'cost_usd' => '0'];
        $succeeded = ['status' => AiRunStatus::Succeeded, ...$this->finished(), ...$usage];
        $this->assertRejectedBy(self::TYPE_COLUMNS, fn () => $this->conversationRun($succeeded));
        $conversationOnly = [
            'agent_definition_id' => AiAgentDefinition::factory()->create()->id,
            'wants_title' => false,
            'compacted' => false,
            'passed_tools' => [],
            'provider' => null,
            'model' => null,
            'reserved_cost_usd' => null,
            'source_record_ids' => null,
            'kind_type' => AiKindType::Transcription,
        ];

        foreach ($conversationOnly as $column => $value) {
            $this->assertRejectedBy(self::TYPE_COLUMNS, fn () => AiRun::factory()->create([$column => $value]));
        }
    }

    /** 確認待ち・止めた・設定・確認は会話の型だけ(9.11 の起きない組み合わせ) */
    public function test_only_a_conversation_run_ends_awaiting_or_stopped_or_fails_with_setting_or_confirmation(): void
    {
        $prompt = fn (array $attributes) => AiRun::factory()->create($attributes);
        $awaiting = ['status' => AiRunStatus::AwaitingConfirmation, ...$this->finished()];
        $this->assertRejectedBy('ai_runs_kind_type_status_check', fn () => $prompt($awaiting));
        $stopped = ['status' => AiRunStatus::Stopped, ...$this->finished()];
        $this->assertRejectedBy('ai_runs_kind_type_status_check', fn () => $prompt($stopped));

        foreach (['setting', 'confirmation'] as $code) {
            $failed = [...$this->finished(), 'status' => AiRunStatus::Failed, 'error_code' => $code];
            $failed['error_message'] = 'x';
            $this->assertRejectedBy('ai_runs_kind_type_error_code_check', fn () => $prompt($failed));
            $this->assertSame($code, $this->conversationRun($failed)->refresh()->error_code?->value);
        }

        $runId = $this->conversationRun()->id;
        $this->assertRejectedBy('ai_runs_status_check', fn () => $this->updateRun($runId, ['status' => 'paused']));
        $this->assertSame(AiRunStatus::AwaitingConfirmation, $this->awaitingRun()->refresh()->status);
    }

    /** 終わりの時刻は 4 つの終わり方と同じ。開始の時刻は待ちでは無く、処理中・成功・確認待ちでは有る(止めたは待ちから止めた S3 があるので見ない) */
    public function test_the_end_and_start_times_follow_the_status(): void
    {
        $finishedAt = 'ai_runs_status_finished_at_check';
        $startedAt = 'ai_runs_status_started_at_check';
        $awaiting = AiRunStatus::AwaitingConfirmation;
        $this->assertRejectedBy(
            $finishedAt,
            fn () => $this->conversationRun(['status' => $awaiting, 'started_at' => now()]),
        );
        $stoppedNow = ['status' => AiRunStatus::Stopped, 'stop_reason' => AiRunStopReason::User];
        $this->assertRejectedBy($finishedAt, fn () => $this->conversationRun($stoppedNow));
        $running = ['status' => AiRunStatus::Running, ...$this->finished()];
        $this->assertRejectedBy($finishedAt, fn () => $this->conversationRun($running));
        $this->assertRejectedBy(
            $startedAt,
            fn () => $this->conversationRun(['status' => $awaiting, 'finished_at' => now()]),
        );
        $this->assertRejectedBy($startedAt, fn () => $this->conversationRun(['started_at' => now()]));

        $stoppedWhileQueued = $this->conversationRun([...$stoppedNow, 'finished_at' => now()]);
        $this->assertNull($stoppedWhileQueued->refresh()->started_at);
        $succeeded = $this->conversationRun(['status' => AiRunStatus::Succeeded, ...$this->finished()]);
        $this->assertNull($succeeded->refresh()->cost_usd);
    }

    /** 止めたと止めた理由は同じ有無。費用の上限の期間は、止めた理由が費用の上限のときだけ有る */
    public function test_the_stop_reason_goes_with_stopped_and_the_budget_period_with_the_budget_reason(): void
    {
        $stopped = ['status' => AiRunStatus::Stopped, ...$this->finished()];
        $this->assertRejectedBy('ai_runs_status_stop_reason_check', fn () => $this->conversationRun($stopped));
        $succeeded = ['status' => AiRunStatus::Succeeded, ...$this->finished(), 'stop_reason' => AiRunStopReason::User];
        $this->assertRejectedBy('ai_runs_status_stop_reason_check', fn () => $this->conversationRun($succeeded));
        $runId = $this->conversationRun($this->stopped(AiRunStopReason::User))->id;
        $this->assertRejectedBy(
            'ai_runs_stop_reason_check',
            fn () => $this->updateRun($runId, ['stop_reason' => 'tired']),
        );
        $budget = 'ai_runs_stop_budget_period_check';
        $withoutPeriod = $this->stopped(AiRunStopReason::Budget);
        $this->assertRejectedBy($budget, fn () => $this->conversationRun($withoutPeriod));
        $userWithPeriod = [...$this->stopped(AiRunStopReason::User), 'stop_budget_period' => 'day'];
        $this->assertRejectedBy($budget, fn () => $this->conversationRun($userWithPeriod));
        $this->assertRejectedBy($budget, fn () => $this->conversationRun(['stop_budget_period' => 'day']));
        $unknownPeriod = [...$this->stopped(AiRunStopReason::Budget), 'stop_budget_period' => 'year'];
        $this->assertRejectedBy($budget, fn () => $this->conversationRun($unknownPeriod));

        foreach (AiRunStopReason::cases() as $reason) {
            $period = $reason === AiRunStopReason::Budget ? 'total' : null;
            $run = $this->conversationRun([...$this->stopped($reason), 'stop_budget_period' => $period])->refresh();
            $this->assertSame([$reason, $period], [$run->stop_reason, $run->stop_budget_period]);
        }
    }

    /** 答えがある行は前の実行があり、題を求めない(題を求められるのは文を送る依頼だけ = 9.10) */
    public function test_an_answer_needs_a_previous_run_and_no_title(): void
    {
        $previousId = $this->awaitingRun()->id;
        $check = 'ai_runs_answer_previous_run_id_check';
        $reject = ['answer' => AiConversationAnswer::Reject];
        $this->assertRejectedBy($check, fn () => $this->conversationRun($reject));
        $titled = [...$reject, 'previous_run_id' => $previousId, 'wants_title' => true];
        $this->assertRejectedBy($check, fn () => $this->conversationRun($titled));
        $answerId = $this->conversationRun([...$reject, 'previous_run_id' => $previousId])->id;
        $this->assertRejectedBy('ai_runs_answer_check', fn () => $this->updateRun($answerId, ['answer' => 'maybe']));

        $this->assertTrue($this->conversationRun(['wants_title' => true])->refresh()->wants_title);
    }

    /** 1 つの実行に続く実行は 1 つ(ADR 0040 の決定 14)。前の実行と定義は、指す実行がある間は消せない */
    public function test_a_previous_run_is_followed_by_one_run_and_cannot_be_deleted(): void
    {
        $previous = $this->awaitingRun();
        $next = $this->conversationRun(['previous_run_id' => $previous->id]);
        $definitions = DB::table('ai_agent_definitions')->where('id', $next->agent_definition_id);

        $this->assertRejectedBy(
            'ai_runs_previous_run_id_unique',
            fn () => $this->conversationRun(['previous_run_id' => $previous->id]),
        );
        $this->assertRejectedBy(
            'ai_runs_previous_run_id_foreign',
            fn () => DB::table('ai_runs')->where('id', $previous->id)->delete(),
        );
        $this->assertRejectedBy('ai_runs_agent_definition_id_foreign', fn () => $definitions->delete());
        $this->assertSame(1, AiRun::query()->where('previous_run_id', $previous->id)->count());
    }

    public function test_the_retry_wait_the_step_sums_and_the_json_columns_have_their_forms(): void
    {
        $retries = [['retry_wait_seconds' => 5], ['retry_attempt' => 1], [5, 0], [-1, 1]];

        foreach ($retries as $retry) {
            $values = array_is_list($retry)
                ? ['retry_wait_seconds' => $retry[0], 'retry_attempt' => $retry[1]]
                : $retry;
            $this->assertRejectedBy('ai_runs_retry_check', fn () => $this->conversationRun($values));
        }

        foreach (['step_cost_sum_usd', 'step_reserved_sum_usd'] as $column) {
            $this->assertRejectedBy(
                'ai_runs_step_sums_check',
                fn () => $this->conversationRun([$column => '-0.000001']),
            );
        }

        $wrongForms = [
            'purposes' => ['a'],
            'passed_tools' => ['a' => 1],
            'knowledge_modules' => ['a' => 1],
            'step_usage_sum' => ['a'],
        ];

        foreach ($wrongForms as $column => $value) {
            $this->assertRejectedBy(
                'ai_runs_conversation_json_check',
                fn () => $this->conversationRun([$column => $value]),
            );
        }

        $waiting = $this->conversationRun(['retry_wait_seconds' => 5, 'retry_attempt' => 2])->refresh();
        $this->assertSame([5, 2], [$waiting->retry_wait_seconds, $waiting->retry_attempt]);
    }

    /**
     * 会話の型の実行(既定は待ち)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function conversationRun(array $attributes = []): AiRun
    {
        return AiRun::factory()->conversation()->create($attributes);
    }

    /** 確認待ちで終わった会話の型の実行 */
    private function awaitingRun(): AiRun
    {
        return $this->conversationRun(['status' => AiRunStatus::AwaitingConfirmation, ...$this->finished()]);
    }

    /**
     * 止めたで終わった属性。
     *
     * @return array<string, mixed>
     */
    private function stopped(AiRunStopReason $reason): array
    {
        return ['status' => AiRunStatus::Stopped, ...$this->finished(), 'stop_reason' => $reason];
    }

    /**
     * 始まって終わった時刻。
     *
     * @return array{started_at: mixed, finished_at: mixed}
     */
    private function finished(): array
    {
        return ['started_at' => now(), 'finished_at' => now()];
    }

    /**
     * 実行の行を直に書き換える(検査制約を確かめるため、列挙のキャストを通さない値を置く)。
     *
     * @param  array<string, mixed>  $values
     */
    private function updateRun(int $runId, array $values): void
    {
        DB::table('ai_runs')->where('id', $runId)->update($values);
    }
}

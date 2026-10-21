<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\PromptAgent;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Prompts\AgentPrompt;
use LogicException;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * AI の実行(docs の 設計/AI/実行と費用.md 4 節・5.1、データと失敗と検査.md 7 節・8 節): 接続先に実行の max_output_tokens を送る。成功は使用量・単価・費用・原文を書いて
 * complete を呼び succeeded にする。失敗の種類ごとの状態(provider・timeout は使用量が分からず費用は NULL、incomplete・completion は使用量と費用を書く、
 * completion は complete の書き込みを残さない、worker_lost はジョブの failed())。失敗は握りつぶさず投げ直す。
 * 接続先は Http::fake(Anthropic の Messages API の形)か PromptAgent::fake で作る(本物を呼ばない)。
 */
final class ExecutePromptTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    private const MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        config(['ai.providers.anthropic.key' => 'fake-key-for-tests']);
    }

    public function test_sends_the_max_output_tokens_of_the_run_and_writes_usage_pricing_cost_and_texts(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->anthropicMessage('{"summary":"要約"}', 'end_turn'))]);
        $runId = $this->queuedRun();

        app(ExecutePrompt::class)->execute($runId);

        Http::assertSent(fn (Request $request): bool => $request['max_tokens'] === 100 && $request['model'] === 'claude-haiku-5-5' && $request['system'] === str_repeat('S', 10)
            && $request['output_config']['effort'] === 'low' && $request['output_config']['format']['type'] === 'json_schema');
        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame(
            [AiRunStatus::Succeeded, 1200, 30, 200, 0, '0.000117', null],
            [$run->status, $run->input_tokens, $run->output_tokens, $run->cache_read_input_tokens, $run->cache_write_input_tokens, $run->cost_usd, $run->error_code],
        );
        $this->assertEquals(['up_to_input_tokens' => 100000, 'input' => 0.1, 'output' => 0.5, 'cache_read' => 0.01, 'cache_write' => 0.125], $run->pricing);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame('{"summary":"要約"}', AiRunText::query()->findOrFail($runId)->output_text);
        $this->assertCount(1, $this->recordingKind->completed);
        $completed = $this->recordingKind->completed[0];
        $this->assertSame([$runId, RecordingPromptKind::CODE, null, $this->requesterUserId(), ['summary' => '要約']], [$completed->runId, $completed->kindCode, $completed->recordId, $completed->requesterUserId, $completed->structured]);
        $this->assertSame(['running'], $this->recordingKind->statusesSeen);
    }

    public function test_the_time_limit_of_the_call_is_the_one_of_the_kind(): void
    {
        $this->setKindSetting(RecordingPromptKind::CODE, 'timeout_seconds', 45);
        PromptAgent::fake([['summary' => '要約']]);

        app(ExecutePrompt::class)->execute($this->queuedRun());

        PromptAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->timeout === 45);
    }

    public function test_the_provider_options_go_only_to_the_provider_of_the_kind(): void
    {
        $agent = new PromptAgent('s', new RecordingPromptKind, 100, 120, 'anthropic', ['output_config' => ['effort' => 'low']]);

        $this->assertSame([['output_config' => ['effort' => 'low']], ['output_config' => ['effort' => 'low']], []], [$agent->providerOptions('anthropic'), $agent->providerOptions(Lab::Anthropic), $agent->providerOptions('openai')]);
    }

    public function test_a_provider_error_fails_without_usage_or_cost(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => '接続先の誤り']], 500)]);

        $run = $this->executeFailing($this->queuedRun());

        $this->assertFailedWithoutUsage($run, AiRunErrorCode::Provider);
    }

    public function test_a_timeout_fails_without_usage_or_cost(): void
    {
        Http::fake([self::MESSAGES_URL => Http::failedConnection('cURL error 28: Operation timed out after 120001 milliseconds with 0 bytes received')]);

        $run = $this->executeFailing($this->queuedRun());

        $this->assertFailedWithoutUsage($run, AiRunErrorCode::Timeout);
    }

    public function test_an_output_cut_by_the_token_limit_fails_as_incomplete_with_usage_and_cost(): void
    {
        Http::fake([self::MESSAGES_URL => Http::response($this->anthropicMessage('{"summary":"途中', 'max_tokens'))]);

        $run = $this->executeFailing($this->queuedRun());

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Incomplete, 1200, '0.000117'], [$run->status, $run->error_code, $run->input_tokens, $run->cost_usd]);
        $this->assertSame('{"summary":"途中', AiRunText::query()->findOrFail($run->id)->output_text);
        $this->assertSame([], $this->recordingKind->completed);
    }

    public function test_an_exception_in_complete_fails_as_completion_and_leaves_none_of_its_writes(): void
    {
        PromptAgent::fake([['summary' => '要約']]);

        $run = $this->executeFailing($this->queuedRun(ThrowingPromptKind::CODE));

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion, ThrowingPromptKind::MESSAGE, 0, '0.000000'], [$run->status, $run->error_code, $run->error_message, $run->input_tokens, $run->cost_usd]);
        $this->assertNotSame(ThrowingPromptKind::WRITTEN, AiRunText::query()->findOrFail($run->id)->system_text);
        $this->assertSame(['summary' => '要約'], json_decode((string) AiRunText::query()->findOrFail($run->id)->output_text, true));
    }

    public function test_the_failed_job_marks_an_unfinished_run_as_worker_lost_and_leaves_a_finished_run(): void
    {
        $runId = $this->queuedRun();
        AiRun::query()->whereKey($runId)->update(['status' => AiRunStatus::Running, 'started_at' => now()]);

        (new RunPromptJob($runId, 100))->failed(new RuntimeException('worker が落ちた'));

        $run = AiRun::query()->findOrFail($runId);
        $this->assertFailedWithoutUsage($run, AiRunErrorCode::WorkerLost);
        $this->assertSame('worker が落ちた', $run->error_message);

        (new RunPromptJob($runId, 100))->failed(new RuntimeException('2 回目'));
        $this->assertSame('worker が落ちた', AiRun::query()->findOrFail($runId)->error_message);
    }

    public function test_a_run_finished_by_another_path_during_the_call_keeps_its_state_and_gets_the_usage(): void
    {
        Exceptions::fake();
        $runId = $this->queuedRun();
        PromptAgent::fake(function () use ($runId): array {
            (new RunPromptJob($runId, 100))->failed(new RuntimeException('worker が落ちた'));

            return ['summary' => '要約'];
        });

        app(ExecutePrompt::class)->execute($runId);

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::WorkerLost, 0, '0.000000'], [$run->status, $run->error_code, $run->input_tokens, $run->cost_usd]);
        $this->assertSame([], $this->recordingKind->completed);
        Exceptions::assertReported(LogicException::class);
    }

    /**
     * complete の後に実行の行の鍵を取ったら、ほかの経路が終わらせていた(1 つの接続で真似るため、ほかの経路の書き込みは complete と同じセーブポイントの中に
     * あって一緒に巻き戻る)。complete の書き込みは巻き戻り、使用量と原文だけが残り、ExecutePrompt は状態を書かない。
     */
    public function test_a_run_finished_by_another_path_during_complete_keeps_only_the_usage(): void
    {
        Exceptions::fake();
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();
        $this->recordingKind->onComplete = function () use ($runId): void {
            AiRunText::query()->whereKey($runId)->update(['system_text' => 'complete が書いた']);
            AiRun::query()->whereKey($runId)->update([
                'status' => AiRunStatus::Failed,
                'error_code' => AiRunErrorCode::WorkerLost,
                'error_message' => '落ちた',
                'finished_at' => now(),
            ]);
        };

        app(ExecutePrompt::class)->execute($runId);

        $run = AiRun::query()->findOrFail($runId);
        $text = AiRunText::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Running, 0, '0.000000'], [$run->status, $run->input_tokens, $run->cost_usd]);
        $this->assertSame(str_repeat('S', 10), $text->system_text);
        $this->assertSame(['summary' => '要約'], json_decode((string) $text->output_text, true));
        Exceptions::assertReported(LogicException::class);
    }

    public function test_a_kind_without_an_implementation_fails_as_completion_before_running(): void
    {
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();
        AiRun::query()->whereKey($runId)->update(['kind' => 'meeting.extraction']);

        $run = $this->executeFailing($runId, LogicException::class);

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion, null], [$run->status, $run->error_code, $run->started_at]);
        $this->assertStringContainsString('meeting.extraction の実装が登録されていない', (string) $run->error_message);
        PromptAgent::assertNeverPrompted();
    }

    public function test_the_job_does_not_retry_and_keeps_the_time_limit_it_was_given(): void
    {
        $job = new RunPromptJob(1, 620);

        $this->assertSame([1, 620], [$job->tries, $job->timeout]);
    }

    public function test_a_run_that_is_not_queued_is_a_logic_error(): void
    {
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();
        app(ExecutePrompt::class)->execute($runId);

        $this->expectException(LogicException::class);

        app(ExecutePrompt::class)->execute($runId);
    }

    private function assertFailedWithoutUsage(AiRun $run, AiRunErrorCode $errorCode): void
    {
        $this->assertSame(
            [AiRunStatus::Failed, $errorCode, null, null, null, null],
            [$run->status, $run->error_code, $run->input_tokens, $run->output_tokens, $run->pricing, $run->cost_usd],
        );
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->error_message);
        $this->assertNull(AiRunText::query()->findOrFail($run->id)->output_text);
    }

    /**
     * Anthropic の Messages API の応答(入力 1,000 + キャッシュの読み 200 = 1,200、出力 30。
     * 費用 = (1,000 × 0.10 + 200 × 0.01 + 30 × 0.50)/ 100 万 = 0.000117)。
     *
     * @return array<string, mixed>
     */
    private function anthropicMessage(string $text, string $stopReason): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 30, 'cache_read_input_tokens' => 200, 'cache_creation_input_tokens' => 0],
        ];
    }
}

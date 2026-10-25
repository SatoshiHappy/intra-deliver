<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\PromptAgent;
use App\Actions\Ai\RunBudget;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Master\Department;
use App\Models\Records\Record;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * AI の実行の依頼(docs の 設計/AI/公開の口.md 3 節の「依頼(文章の種類)」の行、実行と費用.md 5.2、データと失敗と検査.md 8 節): ゲストは 403、未登録の種類は作りの誤り、
 * 入力が長すぎると 422(キー ai)、実行(queued)と送る文を書き、対象のレコードの業務モジュールのコードを引いて写し、ジョブの行は
 * 実行の記録と同じトランザクションで入る(巻き戻れば残らない)。
 * 上限の境界は RequestPromptBudgetTest。
 */
final class RequestPromptTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RunsQueuedJobs;
    use UsesTestPromptKinds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
    }

    public function test_a_guest_cannot_request(): void
    {
        Queue::fake();

        $this->expectException(AuthorizationException::class);

        try {
            app(RequestPrompt::class)->execute($this->guestContext(), $this->promptRequest());
        } finally {
            $this->assertSame(0, AiRun::query()->count());
        }
    }

    public function test_an_unregistered_kind_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);

        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest(self::UNREGISTERED_KIND));
    }

    public function test_a_too_long_input_is_rejected_and_the_limit_itself_passes(): void
    {
        Queue::fake();

        try {
            app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest(userText: str_repeat('あ', 991)));
            $this->fail('長すぎる入力が通った');
        } catch (ValidationException $exception) {
            $this->assertSame([RunBudget::ERROR_KEY => ['AI に送る文が長すぎます(1000 文字まで)']], $exception->errors());
        }

        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest(userText: str_repeat('あ', 990)));
        $this->assertSame(1, AiRun::query()->count());
    }

    public function test_writes_a_queued_run_with_the_settings_the_estimate_and_the_module_of_the_record(): void
    {
        Queue::fake();
        $record = Record::factory()->create(['record_module_id' => $this->recordModule('meetings')->id]);

        $runId = app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest(recordId: $record->id, sourceRecordIds: [$record->id, 7]));

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame(
            [RecordingPromptKind::CODE, AiRunStatus::Queued, $this->requesterUserId(), $record->id, $record->id, 'meetings', [$record->id, 7], 'anthropic', 'claude-haiku-5-5', 100, '0.000270', null, null, null],
            [$run->kind, $run->status, $run->requester_user_id, $run->record_id, $run->original_record_id, $run->record_module_code, $run->source_record_ids, $run->provider, $run->model, $run->max_output_tokens, $run->reserved_cost_usd, $run->cost_usd, $run->input_tokens, $run->started_at],
        );
        $this->assertSame(['default', null], [$run->budget, $run->selected_department_id]);
        $text = AiRunText::query()->findOrFail($runId);
        $this->assertSame([str_repeat('S', 10), str_repeat('U', 90), null], [$text->system_text, $text->user_text, $text->output_text]);
        Queue::assertPushed(RunPromptJob::class, fn (RunPromptJob $job): bool => $job->runId === $runId);
    }

    public function test_the_budget_of_the_kind_and_the_selected_department_are_copied_to_the_run(): void
    {
        Queue::fake();
        $this->setKindSetting(RecordingPromptKind::CODE, 'budget', 'transcription');
        $department = Department::factory()->create();

        $context = $this->requesterContext(selectedDepartmentId: $department->id);

        $runId = app(RequestPrompt::class)->execute($context, $this->promptRequest());

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame(['transcription', $department->id], [$run->budget, $run->selected_department_id]);
    }

    public function test_the_visitor_id_of_the_context_is_written_to_the_run_and_none_stays_empty(): void
    {
        Queue::fake();
        $visitorId = '0b9e8f3a-6c2d-4d1e-9a7b-3f5c1e2d4a6b';

        $requestPrompt = app(RequestPrompt::class);

        $withId = $requestPrompt->execute($this->requesterContext(visitorId: $visitorId), $this->promptRequest());
        $withoutId = $requestPrompt->execute($this->requesterContext(), $this->promptRequest());

        $this->assertSame(
            [$visitorId, null],
            [AiRun::query()->findOrFail($withId)->visitor_id, AiRun::query()->findOrFail($withoutId)->visitor_id],
        );
    }

    public function test_the_job_gets_the_time_limit_from_the_call_limit_of_the_kind(): void
    {
        Queue::fake();

        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());
        $this->setKindSetting(RecordingPromptKind::CODE, 'timeout_seconds', 300);
        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());

        Queue::assertPushed(RunPromptJob::class, fn (RunPromptJob $job): bool => $job->timeout === 220);
        Queue::assertPushed(RunPromptJob::class, fn (RunPromptJob $job): bool => $job->timeout === 620);
    }

    public function test_a_missing_target_record_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);

        app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest(recordId: 999_999));
    }

    /**
     * ジョブの行は実行の記録と同じトランザクションで表に入り、依頼の中では走らない。確定した後に流すと実行が終わる。
     */
    public function test_the_job_row_is_written_with_the_run_and_runs_only_when_the_queue_is_worked(): void
    {
        PromptAgent::fake([['summary' => '要約']]);

        $runId = DB::transaction(function (): int {
            $runId = app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());

            $this->assertSame([$runId], $this->queuedRunIds(RunPromptJob::class));

            return $runId;
        });

        $this->assertSame([$runId], $this->queuedRunIds(RunPromptJob::class));
        PromptAgent::assertNeverPrompted();
        $this->assertSame(AiRunStatus::Queued, AiRun::query()->findOrFail($runId)->status);

        $this->runNextQueuedJob();

        PromptAgent::assertPromptedTimes(1);
        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($runId)->status);
        $this->assertSame([], $this->queuedRunIds(RunPromptJob::class));
    }

    public function test_no_job_row_is_left_when_the_callers_transaction_rolls_back(): void
    {
        PromptAgent::fake([['summary' => '要約']]);

        try {
            DB::transaction(function (): void {
                app(RequestPrompt::class)->execute($this->requesterContext(), $this->promptRequest());

                throw new RuntimeException('呼び手の失敗');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        PromptAgent::assertNeverPrompted();
        $this->assertSame([0, []], [AiRun::query()->count(), $this->queuedRunIds(RunPromptJob::class)]);
    }
}

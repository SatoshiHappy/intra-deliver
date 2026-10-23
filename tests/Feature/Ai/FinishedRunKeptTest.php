<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\PromptAgent;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Closure;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 終わった実行は、あとから来る経路で変えない(docs の 設計/AI/実行と費用.md 4 節)。ほかの経路は、1 つの接続で真似るため、ジョブの failed() を
 * 呼んで終わらせる。
 */
final class FinishedRunKeptTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    private const MESSAGES_URL = 'https://api.anthropic.com/v1/messages';

    private const LOST = 'worker が落ちた';

    /** 完了の受け手が書いた(巻き戻る)文 */
    private const RECEIVER_WROTE = '受け手の書き込み';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        config(['ai.providers.anthropic.key' => 'fake-key-for-tests']);
    }

    /**
     * @return array<string, array{Closure(Request): mixed}>
     */
    public static function connectionFailures(): array
    {
        $timedOut = 'cURL error 28: Operation timed out after 100001 milliseconds';
        $error = ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => '誤り']];

        return [
            '接続先の誤り' => [fn (Request $request) => Http::response($error, 500)],
            '時間切れ' => [fn (Request $request) => Http::failedConnection($timedOut)($request)],
        ];
    }

    /**
     * @param  Closure(Request): mixed  $failure
     */
    #[DataProvider('connectionFailures')]
    public function test_a_connection_failure_after_another_path_finished_the_run_keeps_it(Closure $failure): void
    {
        $runId = $this->queuedRun();
        $finished = null;
        Http::fake([self::MESSAGES_URL => function (Request $request) use ($runId, $failure, &$finished) {
            $this->loseWorker($runId);
            $finished = AiRun::query()->findOrFail($runId)->getAttributes();

            return $failure($request);
        }]);

        $this->executeFailing($runId);

        $this->assertSame($finished, AiRun::query()->findOrFail($runId)->getAttributes());
        $this->assertWorkerLostOnly($runId);
    }

    public function test_a_completion_failure_after_another_path_finished_the_run_keeps_its_state_and_record(): void
    {
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();
        $this->recordingKind->onComplete = fn () => throw new RuntimeException('出力を解釈できない');
        $base = DB::transactionLevel();
        $finished = null;
        // complete の書き込みがすべて巻き戻った直後(失敗を書く前)に、ほかの経路が終わらせる(1 つの接続で真似るため、巻き戻しの後に書く)
        Event::listen(TransactionRolledBack::class, function () use ($runId, $base, &$finished): void {
            if ($finished === null && DB::transactionLevel() === $base) {
                $this->loseWorker($runId);
                $finished = AiRun::query()->findOrFail($runId)->getAttributes();
            }
        });

        $this->executeFailing($runId, RuntimeException::class);

        $this->assertNotNull($finished);
        $this->assertSame($finished, AiRun::query()->findOrFail($runId)->getAttributes());
        $this->assertWorkerLostOnly($runId);
    }

    public function test_the_failed_job_does_not_change_a_succeeded_run_its_result_or_the_receiver(): void
    {
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();
        app(ExecutePrompt::class)->execute($runId);
        $run = AiRun::query()->findOrFail($runId)->getAttributes();
        $outputText = AiRunText::query()->findOrFail($runId)->output_text;

        (new RunPromptJob($runId, 100))->failed(new RuntimeException(self::LOST));

        $this->assertSame($run, AiRun::query()->findOrFail($runId)->getAttributes());
        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($runId)->status);
        $this->assertNull(AiRun::query()->findOrFail($runId)->error_code);
        $this->assertSame($outputText, AiRunText::query()->findOrFail($runId)->output_text);
        $this->assertCount(1, $this->recordingKind->completed);
        $this->assertSame([], $this->recordingKind->failed);
    }

    public function test_a_run_finished_during_the_call_calls_the_receiver_only_once(): void
    {
        Exceptions::fake();
        $runId = $this->queuedRun();
        PromptAgent::fake(function () use ($runId): array {
            $this->loseWorker($runId);

            return ['summary' => '要約'];
        });

        app(ExecutePrompt::class)->execute($runId);

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::WorkerLost], [$run->status, $run->error_code]);
        $this->assertSame([], $this->recordingKind->completed);
        $this->assertSame(['worker_lost'], $this->receivedErrorCodes());
        $this->assertFinishedElsewhereReported($runId);
    }

    /**
     * 最後の確かめ(実行の行の鍵を取った後)で処理中でなかったとき、失敗の受け手を呼ばず、使用量・費用・原文を書いて作りの誤りとして報告する。
     * 同じ接続で先の経路を真似るため、先の経路の書き込みは受け手の書き込みと一緒に巻き戻り、状態は処理中のままになる(巻き戻ったことを確かめる)。
     */
    public function test_a_run_that_is_not_running_at_the_last_check_does_not_call_the_receiver_of_failure(): void
    {
        Exceptions::fake();
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();
        $this->recordingKind->onComplete = function () use ($runId): void {
            AiRun::query()->whereKey($runId)->update([
                'status' => AiRunStatus::Failed,
                'error_code' => AiRunErrorCode::WorkerLost,
                'error_message' => self::LOST,
                'finished_at' => now(),
            ]);
            AiRunText::query()->whereKey($runId)->update(['system_text' => self::RECEIVER_WROTE]);
        };

        app(ExecutePrompt::class)->execute($runId);

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([], $this->recordingKind->failed);
        $this->assertSame(
            [AiRunStatus::Running, null, null, null],
            [$run->status, $run->error_code, $run->error_message, $run->finished_at],
        );
        $this->assertSame(str_repeat('S', 10), AiRunText::query()->findOrFail($runId)->system_text);
        $this->assertNotNull($run->input_tokens);
        $this->assertNotNull($run->cost_usd);
        $output = (string) AiRunText::query()->findOrFail($runId)->output_text;
        $this->assertSame(['summary' => '要約'], json_decode($output, true));
        $this->assertFinishedElsewhereReported($runId);
    }

    private function assertFinishedElsewhereReported(int $runId): void
    {
        Exceptions::assertReported(fn (LogicException $exception): bool => $exception->getMessage()
            === "AI の実行 {$runId} は応答の前にほかの経路で終わっていた(使用量だけを書いた)");
    }

    /** ジョブの failed() = worker が落ちた扱いで、処理中の実行を失敗にする(失敗の受け手も 1 回呼ぶ) */
    private function loseWorker(int $runId): void
    {
        (new RunPromptJob($runId, 100))->failed(new RuntimeException(self::LOST));
    }

    private function assertWorkerLostOnly(int $runId): void
    {
        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame(
            [AiRunStatus::Failed, AiRunErrorCode::WorkerLost, self::LOST],
            [$run->status, $run->error_code, $run->error_message],
        );
        $this->assertSame(['worker_lost'], $this->receivedErrorCodes());
    }

    /**
     * @return list<string> 失敗の受け手に渡った失敗の種類
     */
    private function receivedErrorCodes(): array
    {
        return array_map(fn (FailedRun $failed): string => $failed->errorCode, $this->recordingKind->failed);
    }
}

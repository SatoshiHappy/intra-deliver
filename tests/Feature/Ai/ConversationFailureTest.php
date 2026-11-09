<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationFailed;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\FailingRun;
use App\Actions\Ai\FailRun;
use App\Actions\Ai\RunResult;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Master\User;
use Closure;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 会話の型の実行の失敗の書き方(docs の 設計/AI/実行と費用/README.md 4 節の「完了と失敗の書き方」の F1・F5、受け手 = 設計/AI/エージェントの実行/口と契約.md 9.13):
 * 確認待ち・止めたで終わった実行に失敗の経路(ジョブの失敗の扱いを含む)が来ても何も変えず、受け手を呼ばない(F5)。処理中の実行の失敗では、
 * 会話の型の種類の失敗の受け手が呼ばれ(題は無し)、受け手の中からの依頼は B7(作りの誤り)で断られる(公開の口.md 3 節の表)。
 * ジョブの失敗の扱いは、会話のジョブ(後の区切り)と同じく失敗の書き方の 1 か所(FailRun)を通るので、今のジョブの failed() で真似る。
 */
final class ConversationFailureTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;
    use UsesTestPromptKinds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        $this->registerTestConversationKind();
    }

    /**
     * @return array<string, array{AiRunStatus, AiRunStopReason|null, Closure(int): void}>
     */
    public static function finishedRunsAndFailurePaths(): array
    {
        $failRun = fn (int $runId) => app(FailRun::class)
            ->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));
        $jobFailed = fn (int $runId) => (new RunPromptJob($runId, 100))->failed(new RuntimeException('ジョブの失敗'));

        return [
            '確認待ち・失敗の経路' => [AiRunStatus::AwaitingConfirmation, null, $failRun],
            '確認待ち・ジョブの失敗の扱い' => [AiRunStatus::AwaitingConfirmation, null, $jobFailed],
            '止めた・失敗の経路' => [AiRunStatus::Stopped, AiRunStopReason::User, $failRun],
            '止めた・ジョブの失敗の扱い' => [AiRunStatus::Stopped, AiRunStopReason::TimeLimit, $jobFailed],
        ];
    }

    /**
     * @param  Closure(int): void  $fail
     */
    #[DataProvider('finishedRunsAndFailurePaths')]
    public function test_a_failure_after_the_run_ended_awaiting_confirmation_or_stopped_changes_nothing(
        AiRunStatus $status,
        ?AiRunStopReason $stopReason,
        Closure $fail,
    ): void {
        $runId = $this->conversationRun([
            'status' => $status,
            'started_at' => now(),
            'finished_at' => now(),
            'stop_reason' => $stopReason,
        ]);
        $before = AiRun::query()->findOrFail($runId)->getAttributes();

        $fail($runId);

        $this->assertSame($before, AiRun::query()->findOrFail($runId)->getAttributes());
        $this->assertSame([], $this->conversationKind->failed);
    }

    public function test_a_running_conversation_run_fails_and_calls_the_receiver_of_its_kind_without_a_title(): void
    {
        $runId = $this->conversationRun(['status' => AiRunStatus::Running, 'started_at' => now()]);
        $marked = [];
        $this->conversationKind->onFailed = function (ConversationFailed $run) use (&$marked): void {
            $marked[] = [app(FailingRun::class)->is($run->runId), AiRun::query()->findOrFail($run->runId)->status];
        };

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));

        $run = AiRun::query()->findOrFail($runId);
        $code = RecordingConversationKind::CODE;
        $expected = new ConversationFailed($runId, $code, $run->requester_user_id, 'provider', null, null);
        $this->assertEquals([$expected], $this->conversationKind->failed);
        $this->assertSame([[true, AiRunStatus::Running]], $marked);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Provider], [$run->status, $run->error_code]);
        $this->assertNotNull($run->finished_at);
    }

    /** 会話の型で増えた失敗の種類も、細かい理由は無し */
    public function test_a_setting_failure_has_no_reason(): void
    {
        $runId = $this->conversationRun(['status' => AiRunStatus::Running, 'started_at' => now()]);

        app(FailRun::class)->execute($runId, AiRunErrorCode::Setting, new RuntimeException('道具の定義が大きすぎる'));

        $received = array_map(
            fn (ConversationFailed $run): array => [$run->errorCode, $run->reason],
            $this->conversationKind->failed,
        );
        $this->assertSame([['setting', null]], $received);
        $this->assertSame(AiRunErrorCode::Setting, AiRun::query()->findOrFail($runId)->error_code);
    }

    public function test_a_request_from_the_receiver_of_a_conversation_kind_is_declined(): void
    {
        $runId = $this->conversationRun(['status' => AiRunStatus::Running, 'started_at' => now()]);
        $declined = null;
        $this->conversationKind->onFailed = function (ConversationFailed $run) use (&$declined): void {
            try {
                Queue::fake();
                $context = $this->requesterContext($run->requesterUserId);
                app(RequestPrompt::class)->execute($context, $this->promptRequest());
            } catch (Throwable $exception) {
                $declined = $exception;
            }
        };

        app(FailRun::class)->execute($runId, AiRunErrorCode::WorkerLost, new RuntimeException('worker が落ちた'));

        $this->assertInstanceOf(LogicException::class, $declined);
        $this->assertStringContainsString('失敗の受け手の中', $declined->getMessage());
        $this->assertSame(0, AiRun::query()->where('kind', RecordingPromptKind::CODE)->count());
        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
    }

    /**
     * 使用量・出力の文の片方か両方がある結果(報告の条件 = 使用量が空でないか、出力の文が null でない)。
     *
     * @return array<string, array{RunResult}>
     */
    public static function resultsWithUsageOrOutput(): array
    {
        $used = self::usedResult();

        return [
            '使用量と出力の文' => [$used],
            '使用量だけ' => [new RunResult($used->usage, null)],
            '出力の文だけ' => [new RunResult([], (string) $used->outputText)],
        ];
    }

    /**
     * 会話の型の実行の失敗は、渡された使用量と出力の文を実行の行と原文に書かず(会話の型の使用量と費用はステップの行が持つ)、
     * 作りの誤りとして報告する。失敗は書ける。
     */
    #[DataProvider('resultsWithUsageOrOutput')]
    public function test_a_conversation_failure_writes_neither_the_usage_nor_the_output_and_reports_them(
        RunResult $result,
    ): void {
        Exceptions::fake();
        $runId = $this->conversationRun(['status' => AiRunStatus::Running, 'started_at' => now()]);
        AiRunText::query()->create(['ai_run_id' => $runId, 'system_text' => null, 'user_text' => null]);

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('切れた'), $result);

        $run = AiRun::query()->findOrFail($runId);
        $usage = [$run->input_tokens, $run->output_tokens, $run->pricing, $run->cost_usd];
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Provider], [$run->status, $run->error_code]);
        $this->assertSame([null, null, null, null], $usage);
        $this->assertNull(AiRunText::query()->findOrFail($runId)->output_text);
        Exceptions::assertReported(
            fn (LogicException $exception): bool => str_contains($exception->getMessage(), "会話の型の実行 {$runId} の失敗"),
        );
    }

    /**
     * 使用量も出力の文も無い結果(結果を渡さない・使用量が空で出力の文が null)。
     *
     * @return array<string, array{RunResult|null}>
     */
    public static function resultsWithoutUsageNorOutput(): array
    {
        return [
            '結果を渡さない' => [null],
            '使用量が空で出力の文が null' => [new RunResult([], null)],
        ];
    }

    /** 会話の型の実行の失敗に使用量も出力の文も無ければ、報告しない(失敗は書ける) */
    #[DataProvider('resultsWithoutUsageNorOutput')]
    public function test_a_conversation_failure_without_usage_nor_output_is_not_reported(?RunResult $result): void
    {
        Exceptions::fake();
        $runId = $this->conversationRun(['status' => AiRunStatus::Running, 'started_at' => now()]);

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'), $result);

        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
        Exceptions::assertNotReported(LogicException::class);
    }

    /** 文章の型の実行の失敗は、今のとおり使用量と出力の文を書く(報告しない) */
    public function test_a_prompt_failure_still_writes_the_usage_and_the_output(): void
    {
        Exceptions::fake();
        $runId = $this->queuedRun();

        $failure = new RuntimeException('切れた');
        app(FailRun::class)->execute($runId, AiRunErrorCode::Incomplete, $failure, self::usedResult());

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([10, 3, '0.000025'], [$run->input_tokens, $run->output_tokens, $run->cost_usd]);
        $this->assertSame('{"summary":"途中', AiRunText::query()->findOrFail($runId)->output_text);
        Exceptions::assertNotReported(LogicException::class);
    }

    /** 受け手は行の種類の型の一覧から引く: 会話の型の行のコードが文章の種類としてだけ登録されていても、文章の受け手を呼ばない */
    public function test_a_conversation_run_does_not_call_the_receiver_of_a_prompt_kind_with_the_same_code(): void
    {
        $runId = $this->conversationRun([
            'kind' => RecordingPromptKind::CODE,
            'status' => AiRunStatus::Running,
            'started_at' => now(),
        ]);

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));

        $this->assertSame([[], []], [$this->recordingKind->failed, $this->conversationKind->failed]);
        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
    }

    /** 逆向き: 文章の型の行のコードが会話の型の種類としてだけ登録されていても、会話の受け手を呼ばない */
    public function test_a_prompt_run_does_not_call_the_receiver_of_a_conversation_kind_with_the_same_code(): void
    {
        $runId = AiRun::factory()->running()->create(['kind' => RecordingConversationKind::CODE])->id;

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));

        $this->assertSame([[], []], [$this->recordingKind->failed, $this->conversationKind->failed]);
        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($runId)->status);
    }

    /** 使用量・単価・費用と、途中までの出力の文の分かった結果 */
    private static function usedResult(): RunResult
    {
        $usage = ['input_tokens' => 10, 'output_tokens' => 3, 'pricing' => ['input' => 1.0], 'cost_usd' => '0.000025'];

        return new RunResult($usage, '{"summary":"途中');
    }

    /**
     * 会話の型の実行(依頼者は初期データの最初の利用者)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function conversationRun(array $attributes): int
    {
        $userId = (int) User::query()->orderBy('id')->value('id');

        $run = ['kind' => RecordingConversationKind::CODE, 'requester_user_id' => $userId, ...$attributes];

        return AiRun::factory()->conversation()->create($run)->id;
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\FailRun;
use App\Actions\Ai\MarkRunFailed;
use App\Actions\Ai\PromptKinds;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 実行を失敗にする途中の例外の報告(docs の 設計/AI/実行と費用.md 4 節の「失敗の書き方」)。受け手の例外そのものの扱いは FailedRunReceiverTest。
 */
final class FailRunReportingTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        config(['ai.providers.anthropic.key' => 'fake-key-for-tests']);
    }

    /**
     * 受け手を呼ぶ準備(種類の実装を引く・渡す値を作る)の例外も受け手の例外と同じに扱い、失敗だけを書いて報告する。
     */
    public function test_an_exception_before_the_receiver_is_called_is_reported_and_the_failure_is_written(): void
    {
        Exceptions::fake();
        $runId = $this->queuedRun();
        $this->partialMock(PromptKinds::class, fn (MockInterface $kinds) => $kinds
            ->shouldReceive('find')->andThrow(new RuntimeException('準備の例外')));

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Provider], [$run->status, $run->error_code]);
        $this->assertSame([], $this->recordingKind->failed);
        Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === '準備の例外');
    }

    /**
     * 失敗の書き込みが失敗したら、元の例外を報告してから書き込みの例外を投げる(受け手が投げていれば、受け手の例外も報告する)。
     */
    #[DataProvider('receiverOutcomes')]
    public function test_a_failed_write_reports_the_original_and_throws_the_write_error(bool $receiverThrows): void
    {
        Exceptions::fake();
        $runId = $this->queuedRun();
        $this->recordingKind->failedThrows = $receiverThrows ? new RuntimeException('受け手の例外') : null;
        $this->mock(MarkRunFailed::class, fn (MockInterface $mark) => $mark
            ->shouldReceive('execute')->andThrow(new RuntimeException('書き込みの失敗')));

        $fail = fn () => app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('元の例外'));

        $this->assertThrows($fail, RuntimeException::class, '書き込みの失敗');
        $reported = $receiverThrows ? ['受け手の例外', '元の例外'] : ['元の例外'];
        foreach ($reported as $message) {
            Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $message);
        }
        Exceptions::assertReportedCount(count($reported));
        $this->assertSame(AiRunStatus::Queued, AiRun::query()->findOrFail($runId)->status);
    }

    /**
     * 受け手が投げた後の巻き戻しが失敗しても、受け手の例外を報告する(元の例外も報告し、巻き戻しの例外を投げる)。
     */
    public function test_a_failed_rollback_after_the_receiver_threw_still_reports_the_receiver_error(): void
    {
        Exceptions::fake();
        $runId = $this->queuedRun();
        $this->recordingKind->failedThrows = new RuntimeException('受け手の例外');
        $database = DB::getFacadeRoot();
        $rollBacks = 0;
        $proxy = Mockery::mock($database);
        $proxy->shouldReceive('rollBack')->andReturnUsing(function () use ($database, &$rollBacks): void {
            if ($rollBacks++ === 0) {
                throw new RuntimeException('巻き戻しの失敗');
            }

            $database->rollBack();
        });
        DB::swap($proxy);

        $fail = fn () => app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('元の例外'));

        $this->assertThrows($fail, RuntimeException::class, '巻き戻しの失敗');
        DB::swap($database);
        foreach (['受け手の例外', '元の例外'] as $message) {
            Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $message);
        }
        Exceptions::assertReportedCount(2);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function receiverOutcomes(): array
    {
        return ['受け手が終わった' => [false], '受け手が投げた' => [true]];
    }
}

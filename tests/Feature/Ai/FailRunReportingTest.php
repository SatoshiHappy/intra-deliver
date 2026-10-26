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
     * 失敗の書き込みが失敗しても元の例外(受け手が投げていれば受け手の例外も)が報告に残り、書き込みの例外が投げられる。
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
     * 受け手が投げた後の巻き戻しが失敗しても、受け手の例外と元の例外を報告し、巻き戻しの例外を投げる。続く外の巻き戻しも失敗したら、
     * その例外も報告する(投げるのは最初の巻き戻しの例外。深さは戻らないので、テストの後始末で戻す)。実行は待ちのまま。
     */
    #[DataProvider('failedRollBacks')]
    public function test_a_failed_rollback_after_the_receiver_threw_still_reports_the_receiver_error(
        int $failures,
    ): void {
        Exceptions::fake();
        $runId = $this->queuedRun();
        $this->recordingKind->failedThrows = new RuntimeException('受け手の例外');
        $database = DB::getFacadeRoot();
        $level = DB::transactionLevel();
        $rollBacks = 0;
        $proxy = Mockery::mock($database);
        $proxy->shouldReceive('rollBack')->andReturnUsing(function () use ($database, $failures, &$rollBacks): void {
            if ($rollBacks++ < $failures) {
                throw new RuntimeException("巻き戻しの失敗 {$rollBacks}");
            }

            $database->rollBack();
        });
        DB::swap($proxy);

        try {
            $original = new RuntimeException('元の例外');
            $fail = fn () => app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, $original);
            $this->assertThrows($fail, RuntimeException::class, '巻き戻しの失敗 1');
            $after = $database->transactionLevel();
        } finally {
            DB::swap($database);

            while ($database->transactionLevel() > $level) {
                $database->rollBack();
            }
        }

        $reported = ['受け手の例外', '元の例外', ...($failures === 2 ? ['巻き戻しの失敗 2'] : [])];
        foreach ($reported as $message) {
            Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === $message);
        }
        Exceptions::assertReportedCount(count($reported));
        $this->assertSame(AiRunStatus::Queued, AiRun::query()->findOrFail($runId)->status);
        $this->assertSame($failures === 1 ? $level : $level + 1, $after, '外の巻き戻しが通れば、深さが呼ぶ前に戻る');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function failedRollBacks(): array
    {
        return ['受け手の後の巻き戻しだけが失敗' => [1], '外の巻き戻しも失敗' => [2]];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function receiverOutcomes(): array
    {
        return ['受け手が終わった' => [false], '受け手が投げた' => [true]];
    }
}

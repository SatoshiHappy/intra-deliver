<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\CompletedRun;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RequestPrompt;
use App\Actions\Ai\CompletingRun;
use App\Actions\Ai\FailingRun;
use App\Actions\Ai\FailRun;
use App\Actions\Ai\PromptAgent;
use App\Enums\Ai\AiRunErrorCode;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 受け手を呼んでいる実行の印(docs の 設計/AI/実行と費用.md 4 節の「受け手を呼んでいる実行」): 失敗の受け手を呼ぶ間だけ失敗を書いている実行の印が付き、
 * 受け手が投げても外れる。完了の受け手が投げた後の失敗の書き方では、2 つの印が同時に付かず、受け手の中からの依頼は B7(作りの誤り)になる
 * (設計/AI/公開の口.md 3 節の表)。
 */
final class RunReceiverMarksTest extends TransactionalTestCase
{
    use UsesTestPromptKinds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
    }

    public function test_the_failing_mark_is_on_only_while_the_receiver_runs_even_when_it_throws(): void
    {
        Exceptions::fake();
        $runId = $this->queuedRun();
        $seen = [];
        $this->recordingKind->onFailed = function (FailedRun $run) use (&$seen): void {
            $seen[] = app(FailingRun::class)->is($run->runId);
        };
        $this->recordingKind->failedThrows = new RuntimeException('受け手の例外');

        app(FailRun::class)->execute($runId, AiRunErrorCode::Provider, new RuntimeException('接続先の誤り'));

        $this->assertSame([true], $seen);
        $this->assertFalse(app(FailingRun::class)->any());
    }

    /**
     * 完了の受け手の中は完了中の印だけ、その後の失敗の受け手の中は失敗を書いている印だけが付き、失敗の受け手の中からの依頼は断られる。
     */
    public function test_after_the_completion_receiver_throws_the_failure_receiver_has_only_the_failing_mark(): void
    {
        Exceptions::fake();
        PromptAgent::fake([['summary' => '要約']]);
        $runId = $this->queuedRun();
        $marks = [];
        $declined = null;
        $this->recordingKind->onComplete = function (CompletedRun $run) use (&$marks): void {
            $marks['complete'] = [app(CompletingRun::class)->is($run->runId), app(FailingRun::class)->any()];

            throw new RuntimeException('完了の受け手の例外');
        };
        $this->recordingKind->onFailed = function (FailedRun $run) use (&$marks, &$declined): void {
            $marks['failed'] = [app(CompletingRun::class)->runId(), app(FailingRun::class)->is($run->runId)];

            try {
                Queue::fake();
                $context = $this->requesterContext($run->requesterUserId);
                app(RequestPrompt::class)->execute($context, $this->promptRequest());
            } catch (Throwable $exception) {
                $declined = $exception;
            }
        };

        $this->executeFailing($runId, RuntimeException::class);

        $this->assertSame(['complete' => [true, false], 'failed' => [null, true]], $marks);
        $this->assertInstanceOf(LogicException::class, $declined);
        $this->assertStringContainsString('失敗の受け手の中', $declined->getMessage());
        $this->assertNull(app(CompletingRun::class)->runId());
        $this->assertFalse(app(FailingRun::class)->any());
    }
}

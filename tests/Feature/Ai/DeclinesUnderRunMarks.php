<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\CompletingRun;
use App\Actions\Ai\FailingRun;
use App\Models\Ai\AiRun;
use Closure;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * 依頼の口の断る順のテストの手助け(RequestPromptDeclinedOrderTest・RequestTranscriptionDeclinedOrderTest)。受け手を呼んでいる間の印
 * ({@see CompletingRun}・{@see FailingRun})をテストで付けて依頼し、最初に当たった理由で断られたことを確かめる。
 */
trait DeclinesUnderRunMarks
{
    /**
     * 印を付けて $call を呼び、投げられた例外を返す(投げなければ null)。
     *
     * @param  Closure(): mixed  $call
     */
    protected function thrownUnderMarks(bool $failing, bool $completing, int $runId, Closure $call): ?Throwable
    {
        $thrown = null;
        $request = function () use ($call, &$thrown): void {
            try {
                $call();
            } catch (Throwable $caught) {
                $thrown = $caught;
            }
        };
        $inner = $completing ? fn () => app(CompletingRun::class)->during($runId, $request) : $request;

        if ($failing) {
            app(FailingRun::class)->during($runId, $inner);
        } else {
            $inner();
        }

        return $thrown;
    }

    /**
     * 例外の型と理由の文(の一部)で断られ、実行の行もジョブも増えていない。
     *
     * @param  class-string<Throwable>  $exception
     */
    protected function assertDeclinedWith(?Throwable $thrown, string $exception, string $reason, int $runs): void
    {
        $this->assertNotNull($thrown, '断られるはずの依頼が通った');
        $this->assertSame($exception, $thrown::class);
        $actual = $thrown instanceof RunRequestDeclined ? $thrown->reason : $thrown->getMessage();
        $this->assertStringContainsString($reason, $actual);
        $this->assertSame($runs, AiRun::query()->count());
        Queue::assertNothingPushed();
    }
}

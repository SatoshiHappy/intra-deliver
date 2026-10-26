<?php

namespace App\Actions\Ai;

use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * いま種類の失敗の受け手を呼んでいる実行(失敗を書いている実行。docs の 設計/AI/実行と費用.md 4 節の「受け手を呼んでいる実行」)。
 * {@see FailRun} が受け手を呼ぶ間だけ実行の id を持ち、依頼の口が見分ける(受け手の中からの依頼は作りの誤り =
 * 設計/AI/公開の口.md 3 節の表の B7)。形は完了中の実行 {@see CompletingRun} と同じ。
 * 要求・ジョブごとに作り直す(#[Scoped]。キューの worker はジョブごとに scoped の実体を捨てる)。
 */
#[Scoped]
class FailingRun
{
    private ?int $runId = null;

    /**
     * $runId の失敗の受け手として $callback を呼ぶ(例外でも印を外す)。
     *
     * @param  Closure(): void  $callback
     */
    public function during(int $runId, Closure $callback): void
    {
        $this->runId = $runId;

        try {
            $callback();
        } finally {
            $this->runId = null;
        }
    }

    public function is(int $runId): bool
    {
        return $this->runId === $runId;
    }

    /**
     * どれかの実行の失敗の受け手を呼んでいるか。
     */
    public function any(): bool
    {
        return $this->runId !== null;
    }
}

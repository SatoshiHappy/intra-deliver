<?php

namespace App\Actions\Ai;

use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * いま種類の実装の complete を呼んでいる実行。ExecutePrompt が complete の間だけ実行の id を持ち、書き込みの口が照らし合わせる
 * (complete の外 = worker の外や別の実行から書かせない。設計/AI/公開の口.md 3.2「呼べる時」)。
 * 要求・ジョブごとに作り直す(#[Scoped]。キューの worker はジョブごとに scoped の実体を捨てる)。
 */
#[Scoped]
class CompletingRun
{
    private ?int $runId = null;

    /**
     * $runId の complete として $callback を呼ぶ(例外でも印を外す)。
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
}

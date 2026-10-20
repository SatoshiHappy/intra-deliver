<?php

namespace App\Jobs\Ai;

use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\FailRun;
use App\Enums\Ai\AiRunErrorCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * AI の実行のジョブ(Ai に 1 つだけ。docs の 設計/AI/実行と費用.md 4 節)。引数は実行の id と時間の上限(送る文は ai_run_texts から読む)。
 * 再試行しない(同じ依頼を 2 回呼ぶと費用が 2 回かかる。失敗したら利用者が依頼し直す)。時間の上限の決め方は 4.1。
 * キューの retry_after より短いことは起動のテストが確かめる。ジョブは DB を直接書かず、業務処理を呼ぶ。
 */
final class RunPromptJob implements ShouldQueue
{
    use Queueable;

    /** 試行の回数(再試行しない) */
    public int $tries = 1;

    /**
     * @param  int  $timeout  ジョブの時間の上限(秒)
     */
    public function __construct(public readonly int $runId, public int $timeout) {}

    public function handle(ExecutePrompt $executePrompt): void
    {
        $executePrompt->execute($this->runId);
    }

    /**
     * ジョブが失敗した(worker が落ちて試行の回数を超えた・例外で終わった)ときの救済。ExecutePrompt が失敗にした実行は FailRun が変えない。
     */
    public function failed(?Throwable $exception): void
    {
        app(FailRun::class)->execute($this->runId, AiRunErrorCode::WorkerLost, $exception);
    }
}

<?php

namespace App\Jobs\Ai;

use App\Actions\Ai\Conversation\ExecuteConversation;
use App\Actions\Ai\FailRun;
use App\Enums\Ai\AiRunErrorCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 会話の型のジョブ(会話の専用のキューの接続で動かす = docs の 設計/AI/実行と費用/README.md 4.5。接続は入れる側が選ぶ)。引数は実行の id と
 * ジョブの時間の上限(式 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.7)。再試行しない(4.5)。ジョブは DB を直接書かず、業務処理を呼ぶ。
 */
final class RunConversationJob implements ShouldQueue
{
    use Queueable;

    /** 試行の回数(再試行しない) */
    public int $tries = 1;

    /**
     * @param  int  $timeout  ジョブの時間の上限(秒)
     */
    public function __construct(public readonly int $runId, public int $timeout) {}

    public function handle(ExecuteConversation $execute): void
    {
        $execute->execute($this->runId);
    }

    /**
     * ジョブが失敗した(worker が落ちて試行の回数を超えた・例外で終わった)ときの救済(S22)。失敗に書いた実行は FailRun が変えない。
     */
    public function failed(?Throwable $exception): void
    {
        app(FailRun::class)->execute($this->runId, AiRunErrorCode::WorkerLost, $exception);
    }
}

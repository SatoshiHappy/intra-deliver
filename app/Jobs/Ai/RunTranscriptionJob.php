<?php

namespace App\Jobs\Ai;

use App\Actions\Ai\ExecuteTranscription;
use App\Actions\Ai\FailRun;
use App\Enums\Ai\AiRunErrorCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * AI の文字起こしの実行のジョブ(docs の 設計/AI/実行と費用.md 4 節)。引数は実行の id と時間の上限(音声は実行の記録の添付から読む)。
 * 再試行しない(同じ依頼を 2 回呼ぶと費用が 2 回かかる)。時間の上限 = 変換と区切りの数の上限の分を含めた長さ(4.1)。
 * キューの retry_after より短いことは起動のテストが確かめる。ジョブは DB を直接書かず、業務処理を呼ぶ。
 */
final class RunTranscriptionJob implements ShouldQueue
{
    use Queueable;

    /** 試行の回数(再試行しない) */
    public int $tries = 1;

    /**
     * @param  int  $timeout  ジョブの時間の上限(秒)
     */
    public function __construct(public readonly int $runId, public int $timeout) {}

    public function handle(ExecuteTranscription $executeTranscription): void
    {
        $executeTranscription->execute($this->runId);
    }

    /**
     * ジョブが失敗した(worker が落ちて試行の回数を超えた・例外で終わった)ときの救済。ExecuteTranscription が失敗にした実行は FailRun が変えない。
     * worker が落ちて残った区切りの一時ファイルは毎日の削除が消す(設計/AI/データと失敗と検査.md 6 節)。
     */
    public function failed(?Throwable $exception): void
    {
        app(FailRun::class)->execute($this->runId, AiRunErrorCode::WorkerLost, $exception);
    }
}

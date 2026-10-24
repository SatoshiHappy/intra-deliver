<?php

namespace App\Actions\Reset;

use App\Enums\Reset\ResetOutcome;

/**
 * 初期化の 1 回の結果(コマンドの出力 = docs の 設計/公開の配備/毎日の初期化.md 5 節、配備と戻し方.md 6.3 の「5 の結果と次の操作」)。
 * 始めなかった回は記録の行の番号を持たない。失敗の回は {@see ResetFailed} が持つ。
 */
final readonly class ResetReport
{
    public function __construct(
        public ResetOutcome $outcome,
        public ?int $runId = null,
        public int $waitedJobs = 0,
        public int $waitedSeconds = 0,
        public ?float $reloadSeconds = null,
        public int $peakMemoryBytes = 0,
    ) {}
}

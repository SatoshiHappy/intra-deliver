<?php

namespace App\Console\Commands\Reset;

use App\Actions\Reset\ResetFailed;
use App\Actions\Reset\ResetReport;
use App\Actions\Reset\ResetToInitialData;
use App\Enums\Reset\ResetOutcome;
use App\Enums\Reset\ResetTrigger;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * 初期化を流すコマンドの共通の部分(手で流す {@see ResetData} と、予定の {@see RunDailyReset})。
 * 出力と終了コード = docs の 設計/公開の配備/毎日の初期化.md 2 節の「始めるか」の表の出力と終了コードの列。失敗の例外はログに報告する。
 */
abstract class ResetCommand extends Command
{
    private const BYTES_PER_MB = 1024 * 1024;

    private const NOT_STARTED = [
        'disabled' => '設定がオフのため、何もしませんでした(DAILY_RESET_ENABLED)',
        'locked' => 'ほかの初期化が動いているため、始めませんでした(初期化の鎖錠を取れない)',
        'maintenance' => '初期化の印の無いメンテナンスの状態があるため、始めませんでした',
        'already-tried' => 'その日の初期化は試し済みのため、何もしませんでした',
    ];

    protected function resetWith(ResetToInitialData $reset, ResetTrigger $trigger): int
    {
        try {
            $report = $reset->execute($trigger, fn (int $runId) => $this->line("初期化の記録の行: {$runId}"));
        } catch (ResetFailed $failed) {
            $this->print($failed->report);
            $error = $failed->getPrevious() ?? $failed;
            report($error);
            $this->error('失敗の理由: '.$error->getMessage());

            return $failed->report->outcome->exitCode($trigger);
        }

        if ($report->runId === null) {
            if ($report->outcome->printsReason($trigger)) {
                $this->line(self::NOT_STARTED[$report->outcome->value] ?? $report->outcome->value);
            }

            return $report->outcome->exitCode($trigger);
        }

        $this->print($report);

        return $report->outcome->exitCode($trigger);
    }

    private function print(ResetReport $report): void
    {
        $result = $report->outcome === ResetOutcome::Succeeded ? '成功' : '失敗';
        $reload = $report->reloadSeconds === null ? '—' : "{$report->reloadSeconds} 秒";
        $memory = round($report->peakMemoryBytes / self::BYTES_PER_MB, 1);

        $this->line("手順 2 で待ったジョブ: {$report->waitedJobs} 件・{$report->waitedSeconds} 秒");
        $this->line("メモリの最大: {$memory} MB・手順 5(入れ直し): {$reload}");
        $this->line('終わり: '.CarbonImmutable::now(AccessContext::TIMEZONE)->toIso8601String()."・成否: {$result}");
    }
}

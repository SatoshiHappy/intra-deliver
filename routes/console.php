<?php

use App\Console\Commands\Ai\PruneRunTexts;
use App\Console\Commands\Meeting\PurgeMeetingMedia;
use App\Console\Commands\Reset\RunDailyReset;
use App\Console\Commands\Reset\WatchReset;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// AI の原文と例外の文の保存期間(docs の 設計/AI/データと失敗と検査.md 6 節)。失敗したジョブの行(failed_jobs の例外の文)も同じ期間で消す
Schedule::command(PruneRunTexts::class)->daily();
Schedule::command('queue:prune-failed', ['--hours' => PruneRunTexts::failedJobRetentionHours()])->daily();

// 削除予定日を過ぎた会議の音声・動画の実体の削除(docs の 設計/業務ツール/会議/通知と削除予定日.md の 7.2)。東京の毎日、日付が変わった直後に動かす
Schedule::command(PurgeMeetingMedia::class)->daily()->timezone('Asia/Tokyo');

// 公開デモの毎日の初期化と見張り(docs の 設計/公開の配備/毎日の初期化.md 2 節・4 節・5 節)。見張りを先に流す。
// 予定のコマンドの出力は既定で捨てられるので、コンテナのログへ向ける
if (config('reset.enabled') === true) {
    foreach ([WatchReset::class, RunDailyReset::class] as $command) {
        Schedule::command($command)->everyMinute()->evenInMaintenanceMode()
            ->appendOutputTo((string) config('reset.schedule_output'));
    }
}

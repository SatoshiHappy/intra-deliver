<?php

use App\Console\Commands\Ai\PruneRunTexts;
use App\Console\Commands\Meeting\PurgeMeetingMedia;
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

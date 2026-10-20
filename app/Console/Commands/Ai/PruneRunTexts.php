<?php

namespace App\Console\Commands\Ai;

use App\Actions\Ai\PruneRunTexts as PruneRunTextsAction;
use App\Queries\Ai\AiSettings;
use Illuminate\Console\Command;

/**
 * 保存期間を過ぎた AI の原文・例外の文を消すコマンド(docs の 設計/AI/データと失敗と検査.md 6 節)。スケジューラの定義(routes/console.php)が毎日動かす。
 * 配線のファイルは業務処理を名指せないので、このコマンドが業務処理を呼ぶ。
 */
final class PruneRunTexts extends Command
{
    protected $signature = 'ai:prune-run-texts';

    protected $description = '保存期間を過ぎた AI の送った文・応答の原文・例外の文を消す';

    /**
     * 失敗したジョブの行(failed_jobs の例外の文)を残す時間 = AI の保存期間(スケジューラの定義が queue:prune-failed に渡す。
     * 配線のファイルは業務処理を名指せないので、検証した保存期間をここから渡す)。
     */
    public static function failedJobRetentionHours(): int
    {
        return app(AiSettings::class)->retentionDays() * 24;
    }

    public function handle(PruneRunTextsAction $pruneRunTexts): int
    {
        $pruned = $pruneRunTexts->execute();
        $this->info("AI の原文を {$pruned['texts']} 件消し、例外の文を {$pruned['errorMessages']} 件空にした");

        return self::SUCCESS;
    }
}

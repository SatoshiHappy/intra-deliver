<?php

namespace App\Console\Commands\Meeting;

use App\Actions\Records\Api\PurgeRecordMedia;
use App\Enums\Builtin\Meeting;
use App\Queries\Records\Api\RecordDueQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * 削除予定日を過ぎた会議の音声・動画の実体を消すコマンド(docs の 設計/業務ツール/会議/通知と削除予定日.md の 7.2)。スケジューラの定義
 * (routes/console.php)が東京の毎日動かす。対象 = 削除予定日が東京の今日より前の会議。会議ごとに入れ物の期限の削除の口を呼び(口が自分の
 * トランザクションを持ち、行の鍵の下で削除予定日を読み直す)、ある会議の失敗はその会議を飛ばして報告し(ログと標準エラー)、最後に失敗があれば失敗で終える。
 * 配線のファイルは業務処理を名指せないので、このコマンドが業務処理を呼ぶ。
 */
final class PurgeMeetingMedia extends Command
{
    protected $signature = 'meeting:purge-media';

    protected $description = '削除予定日を過ぎた会議の音声・動画の実体を消す';

    public function handle(RecordDueQuery $due, PurgeRecordMedia $purge): int
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);
        $dueField = Meeting::PurgeDueOn->value;
        $purged = 0;
        $failed = 0;

        foreach ($due->idsBefore(Meeting::CODE, $dueField, $today) as $recordId) {
            try {
                $purged += $purge->execute(Meeting::CODE, $recordId, $dueField, $today);
            } catch (Throwable $exception) {
                report($exception);
                $this->output->getErrorStyle()->writeln("会議 {$recordId} の音声・動画を消せなかった: {$exception->getMessage()}");
                $failed++;
            }
        }

        $this->info("削除予定日を過ぎた会議の音声・動画の実体を {$purged} 件消した");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Console\Commands\Meeting;

use App\Actions\Records\Api\PurgeRecordMedia;
use App\Enums\Builtin\Meeting;
use App\Queries\Records\Api\RecordDueQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * 削除予定日を過ぎた会議の音声・動画の実体を消すコマンド(docs の 設計/業務ツール/会議/通知と削除予定日.md の 7.2)。ある会議の実行時の失敗
 * (会議が消えた・DB)は、ほかの会議の削除を止めないために飛ばして報告する。配線のファイルは業務処理を名指せないので、このコマンドが業務処理を呼ぶ。
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
            } catch (RuntimeException $exception) {
                report($exception);
                $this->output->getErrorStyle()->writeln("会議 {$recordId} の音声・動画を消せなかった: {$exception->getMessage()}");
                $failed++;
            }
        }

        $this->info("削除予定日を過ぎた会議の音声・動画の実体を {$purged} 件消した扱いにした");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}

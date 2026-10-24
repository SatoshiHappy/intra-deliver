<?php

namespace App\Console\Commands\Upload;

use App\Actions\Upload\PruneUploadFiles as PruneUploadFilesAction;
use Illuminate\Console\Command;

/**
 * 台帳が持たない実体を消すコマンド(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 6 節の 5)。
 * スケジューラの定義(routes/console.php)が毎日動かす。配線のファイルは業務処理を名指せないので、このコマンドが業務処理を呼ぶ。
 */
final class PruneUploadFiles extends Command
{
    protected $signature = 'upload:prune-files';

    protected $description = '台帳が持たない添付の実体を消す';

    public function handle(PruneUploadFilesAction $prune): int
    {
        $result = $prune->execute();
        $this->info("台帳が持たない添付の実体を {$result['deleted']} 件消した");

        if ($result['failed'] === 0) {
            return self::SUCCESS;
        }

        $this->output->getErrorStyle()->writeln("消せなかった添付の実体が {$result['failed']} 件ある");

        return self::FAILURE;
    }
}

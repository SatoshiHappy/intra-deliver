<?php

namespace App\Console\Commands\Reset;

use App\Actions\Reset\ResetToInitialData;
use App\Enums\Reset\ResetTrigger;

/**
 * 予定の初期化(docs の 設計/公開の配備/毎日の初期化.md 2 節)。配線のファイル(routes/console.php)は業務処理を名指せないので、このコマンドが呼ぶ。
 */
final class RunDailyReset extends ResetCommand
{
    protected $signature = 'reset:daily';

    protected $description = 'その日の初期化をまだ試していなければ流す(スケジューラが毎分動かす)';

    public function handle(ResetToInitialData $reset): int
    {
        return $this->resetWith($reset, ResetTrigger::Schedule);
    }
}

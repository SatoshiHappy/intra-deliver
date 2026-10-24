<?php

namespace App\Console\Commands\Reset;

use App\Actions\Reset\ResetToInitialData;
use App\Enums\Reset\ResetTrigger;

/**
 * 初期化を手で流すコマンド(docs の 設計/公開の配備/配備と戻し方.md 6.7 の「DB を初期データへ戻す」、毎日の初期化.md 6 節)。
 */
final class ResetData extends ResetCommand
{
    protected $signature = 'reset:data';

    protected $description = 'DB とファイルを初期データへ戻す(公開デモの毎日の初期化を手で流す)';

    public function handle(ResetToInitialData $reset): int
    {
        return $this->resetWith($reset, ResetTrigger::Manual);
    }
}

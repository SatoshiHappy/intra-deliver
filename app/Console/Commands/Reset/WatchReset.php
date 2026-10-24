<?php

namespace App\Console\Commands\Reset;

use App\Actions\Reset\LiftStaleReset;
use App\Queries\Reset\ResetSettings;
use Illuminate\Console\Command;

/**
 * 見張り(docs の 設計/公開の配備/毎日の初期化.md 4 節)。
 */
final class WatchReset extends Command
{
    protected $signature = 'reset:watch';

    protected $description = '落ちた初期化の停止を解き、記録の行を失敗で閉じる(スケジューラが毎分動かす)';

    public function handle(ResetSettings $settings, LiftStaleReset $lift): int
    {
        if (! $settings->enabled()) {
            $this->line('設定がオフのため、何もしませんでした(DAILY_RESET_ENABLED)');

            return self::FAILURE;
        }

        $lifted = $lift->execute();

        if ($lifted === null) {
            return self::SUCCESS;
        }

        if ($lifted['maintenance'] || $lifted['runs'] !== []) {
            $runs = $lifted['runs'] === [] ? 'なし' : implode('・', $lifted['runs']);
            $this->line('落ちた初期化を片付けた: メンテナンスの状態を'.($lifted['maintenance'] ? '解いた' : '解いていない')."・失敗で閉じた行 = {$runs}");
        }

        return self::SUCCESS;
    }
}

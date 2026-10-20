<?php

namespace App\Console\Commands\Alpha;

use Illuminate\Console\Command;

/**
 * コマンド(スケジューラの定義が名指す)
 */
final class PruneAlpha extends Command
{
    protected $signature = 'alpha:prune';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}

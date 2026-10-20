<?php

namespace App\Console\Commands\Alpha;

use App\Actions\Alpha\AlphaAction;
use App\Enums\Alpha\AlphaStatus;
use App\Queries\Alpha\AlphaQuery;
use App\Support\SupportValue;
use Illuminate\Console\Command;

/**
 * コマンド → 業務処理・読み取り・列挙・共有・外部(DB を除く)
 */
final class AlphaCommand extends Command
{
    public function handle(AlphaAction $alphaAction, AlphaQuery $alphaQuery, AlphaStatus $alphaStatus, SupportValue $supportValue): int
    {
        return self::SUCCESS;
    }
}

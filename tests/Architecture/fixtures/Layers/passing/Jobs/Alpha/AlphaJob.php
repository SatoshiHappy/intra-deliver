<?php

namespace App\Jobs\Alpha;

use App\Actions\Alpha\AlphaAction;
use App\Enums\Alpha\AlphaStatus;
use App\Queries\Alpha\AlphaQuery;
use App\Support\SupportValue;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ジョブ → 業務処理・読み取り・列挙・共有・外部(DB を除く)
 */
final class AlphaJob implements ShouldQueue
{
    public function handle(AlphaAction $alphaAction, AlphaQuery $alphaQuery, AlphaStatus $alphaStatus, SupportValue $supportValue): AlphaAction
    {
        return $alphaAction;
    }
}

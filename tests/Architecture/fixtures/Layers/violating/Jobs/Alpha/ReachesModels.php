<?php

namespace App\Jobs\Alpha;

use App\Models\Alpha\AlphaItem;

/**
 * ジョブ → 永続化は禁止(書き込みは業務処理を通す)
 */
final class ReachesModels
{
    public function usesAlphaItem(AlphaItem $alphaItem): AlphaItem
    {
        return $alphaItem;
    }
}

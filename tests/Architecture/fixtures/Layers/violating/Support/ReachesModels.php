<?php

namespace App\Support;

use App\Models\Alpha\AlphaItem;

/**
 * 共有 → 永続化は禁止
 */
final class ReachesModels
{
    public function usesAlphaItem(AlphaItem $alphaItem): AlphaItem
    {
        return $alphaItem;
    }
}

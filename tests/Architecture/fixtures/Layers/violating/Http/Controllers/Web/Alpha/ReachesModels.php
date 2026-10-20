<?php

namespace App\Http\Controllers\Web\Alpha;

use App\Models\Alpha\AlphaItem;

/**
 * 入口 → 永続化は禁止
 */
final class ReachesModels
{
    public function usesAlphaItem(AlphaItem $alphaItem): AlphaItem
    {
        return $alphaItem;
    }
}

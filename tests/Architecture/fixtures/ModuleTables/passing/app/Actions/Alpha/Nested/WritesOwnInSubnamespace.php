<?php

namespace App\Actions\Alpha\Nested;

use App\Models\Alpha\AlphaItem;

/**
 * 持ち主の置き場の配下(App\Actions\Alpha\…)も書ける
 */
final class WritesOwnInSubnamespace
{
    public function writes(AlphaItem $item): void
    {
        $item->delete();
    }
}

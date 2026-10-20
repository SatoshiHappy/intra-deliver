<?php

namespace App\Actions\AlphaExtra;

use App\Models\Alpha\AlphaItem;

/**
 * 名前が Alpha で始まる別のモジュール(App\Actions\AlphaExtra)は Alpha の持ち主ではない
 */
final class WritesAlpha
{
    public function writes(AlphaItem $item): void
    {
        $item->touch();
    }
}

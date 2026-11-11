<?php

namespace App\Queries\Alpha;

use App\Models\Alpha\AlphaItem;

/**
 * 読み取りから DB への書き込み
 */
final class WritesFromQuery
{
    public function save(AlphaItem $item): void
    {
        $item->save();
    }
}

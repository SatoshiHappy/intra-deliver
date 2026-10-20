<?php

namespace App\Queries\Alpha;

use App\Models\Alpha\AlphaItem;

/**
 * 抑制の注釈を付けても副作用の規則は消せない(nonIgnorable)
 */
final class SuppressedWrite
{
    public function delete(AlphaItem $item): void
    {
        // @phpstan-ignore sideEffect.dbWrite
        $item->delete();
    }
}

<?php

namespace App\Queries\Alpha;

use App\Models\Alpha\AlphaItem;

/**
 * 同じモジュールでも業務処理の置き場(App\Actions\Alpha)の外からは書けない
 */
final class WritesOwnModel
{
    public function writes(AlphaItem $item): void
    {
        $item->update(['name' => 'x']);
    }
}

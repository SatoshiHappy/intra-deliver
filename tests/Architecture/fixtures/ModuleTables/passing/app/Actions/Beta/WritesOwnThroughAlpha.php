<?php

namespace App\Actions\Beta;

use App\Models\Alpha\AlphaItem;

/**
 * リレーションの書き込みは関連先の表で判定する(Alpha のモデルのリレーションから Beta の表を書くのは Beta の業務処理で可)
 */
final class WritesOwnThroughAlpha
{
    public function writes(AlphaItem $item): void
    {
        $item->betaItems()->create(['name' => 'x']);
    }
}

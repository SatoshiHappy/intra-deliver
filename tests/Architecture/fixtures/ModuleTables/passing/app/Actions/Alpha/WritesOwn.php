<?php

namespace App\Actions\Alpha;

use App\Models\Alpha\AlphaItem;
use App\Models\Beta\BetaItem;

/**
 * モジュール Alpha の業務処理は自分の表を書ける(書き方に依らない)。ほかのモジュールの表は読むだけ
 */
final class WritesOwn
{
    public function writes(AlphaItem $item, ?AlphaItem $maybe, BetaItem $beta): int
    {
        $item->save();
        AlphaItem::query()->whereKey(1)->update(['name' => 'x']);
        AlphaItem::create(['name' => 'x']);
        $beta->alphaItems()->create(['name' => 'x']);
        $maybe?->delete();

        return BetaItem::query()->count();
    }
}

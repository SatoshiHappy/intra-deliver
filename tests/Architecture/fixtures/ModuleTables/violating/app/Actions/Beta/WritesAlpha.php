<?php

namespace App\Actions\Beta;

use App\Models\Alpha\AlphaItem;
use App\Models\Beta\BetaItem;

/**
 * モジュール Beta の業務処理がモジュール Alpha の表を書くのは禁止(書き方に依らない)
 */
final class WritesAlpha
{
    public function writes(AlphaItem $item, ?AlphaItem $maybe, BetaItem $beta): void
    {
        $item->save();
        AlphaItem::query()->whereKey(1)->update(['name' => 'x']);
        AlphaItem::create(['name' => 'x']);
        $beta->alphaItems()->create(['name' => 'x']);
        $maybe?->delete();
        // @phpstan-ignore moduleTableWrite
        $item->forceDelete();
    }
}

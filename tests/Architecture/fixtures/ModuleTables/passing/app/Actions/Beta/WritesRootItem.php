<?php

namespace App\Actions\Beta;

use App\Models\RootItem;

/**
 * モデルの置き場の直下のモデルはモジュールが分からないので判定しない(ほかのモジュールの業務処理が書いても通る)
 */
final class WritesRootItem
{
    public function writes(RootItem $item): void
    {
        $item->save();
        RootItem::create(['name' => 'x']);
    }
}

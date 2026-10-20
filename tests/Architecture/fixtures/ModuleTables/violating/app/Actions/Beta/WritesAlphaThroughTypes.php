<?php

namespace App\Actions\Beta;

use App\Models\Alpha\AlphaItem;
use App\Models\Beta\BetaItem;

/**
 * 型が和の受け手(null を含む型を含む)と class-string の静的呼び出しも、ほかのモジュールの表の書き込みとして判定する
 */
final class WritesAlphaThroughTypes
{
    /**
     * @param  class-string<AlphaItem>  $class
     */
    public function writes(bool $flag, BetaItem $beta, string $class): void
    {
        $query = $flag ? AlphaItem::query() : $beta->alphaItems();
        $query->delete();
        $class::create(['name' => 'x']);
        $found = AlphaItem::query()->find(1);
        $found?->delete();
    }
}

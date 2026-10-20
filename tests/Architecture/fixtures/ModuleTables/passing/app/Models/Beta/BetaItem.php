<?php

namespace App\Models\Beta;

use App\Models\Alpha\AlphaItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * モジュール Beta のモデル
 */
final class BetaItem extends Model
{
    /**
     * @return HasMany<AlphaItem, $this>
     */
    public function alphaItems(): HasMany
    {
        return $this->hasMany(AlphaItem::class);
    }
}

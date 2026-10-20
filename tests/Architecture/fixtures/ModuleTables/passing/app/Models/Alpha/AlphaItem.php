<?php

namespace App\Models\Alpha;

use App\Models\Beta\BetaItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * モジュール Alpha のモデル
 */
final class AlphaItem extends Model
{
    /**
     * @return HasMany<BetaItem, $this>
     */
    public function betaItems(): HasMany
    {
        return $this->hasMany(BetaItem::class);
    }
}

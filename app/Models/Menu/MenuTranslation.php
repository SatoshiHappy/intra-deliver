<?php

namespace App\Models\Menu;

use App\Models\Master\Locale;
use Database\Factories\Menu\MenuTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * メニューのロケール別の表示名(本家の b_m_menu_i)。表 menu_translations(docs の 設計/メニュー/表.md)。
 */
#[Fillable(['menu_id', 'locale_id', 'display_name'])]
class MenuTranslation extends Model
{
    /** @use HasFactory<MenuTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}

<?php

namespace App\Models\Menu;

use App\Models\Master\Locale;
use Database\Factories\Menu\MenuGroupCategoryTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * メニューグループカテゴリのロケール別の表示名(本家の b_m_menu_group_category_i)。表 menu_group_category_translations(docs の 設計/メニュー/表.md)。
 */
#[Fillable(['menu_group_category_id', 'locale_id', 'display_name'])]
class MenuGroupCategoryTranslation extends Model
{
    /** @use HasFactory<MenuGroupCategoryTranslationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MenuGroupCategory, $this>
     */
    public function menuGroupCategory(): BelongsTo
    {
        return $this->belongsTo(MenuGroupCategory::class);
    }

    /**
     * @return BelongsTo<Locale, $this>
     */
    public function locale(): BelongsTo
    {
        return $this->belongsTo(Locale::class);
    }
}

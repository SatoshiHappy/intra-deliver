<?php

namespace App\Models\Menu;

use Database\Factories\Menu\MenuGroupCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * メニューグループカテゴリ(期間なしのマスタ。並び順の列は持たず、一覧はコードの順)。表 menu_group_categories(docs の 設計/メニュー/表.md 1.1)。
 */
#[Fillable(['code', 'is_selectable', 'is_editable'])]
class MenuGroupCategory extends Model
{
    /** @use HasFactory<MenuGroupCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_selectable' => 'boolean',
            'is_editable' => 'boolean',
        ];
    }

    /**
     * @return HasMany<MenuGroupCategoryTranslation, $this>
     */
    public function menuGroupCategoryTranslations(): HasMany
    {
        return $this->hasMany(MenuGroupCategoryTranslation::class);
    }

    /**
     * @return HasMany<MenuGroupCategoryInclusion, $this>
     */
    public function menuGroupCategoryInclusions(): HasMany
    {
        return $this->hasMany(MenuGroupCategoryInclusion::class);
    }
}

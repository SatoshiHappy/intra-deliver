<?php

namespace App\Models\Menu;

use Database\Factories\Menu\MenuGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * メニューグループ(期間なしのマスタ)。表 menu_groups(docs の 設計/メニュー/表.md 1.2)。
 * グループ ID と表示名は列に持たず、ルートのメニューの code と表示名を読む。
 */
#[Fillable(['menu_id'])]
class MenuGroup extends Model
{
    /** @use HasFactory<MenuGroupFactory> */
    use HasFactory;

    /**
     * ルートのメニュー。
     *
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    /**
     * @return HasMany<MenuGroupCategoryInclusion, $this>
     */
    public function menuGroupCategoryInclusions(): HasMany
    {
        return $this->hasMany(MenuGroupCategoryInclusion::class);
    }
}

<?php

namespace App\Models\Menu;

use Database\Factories\Menu\MenuGroupCategoryInclusionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * メニューグループのカテゴリへの所属(期間なしの関係。1 つのグループは複数のカテゴリに属せる)。表 menu_group_category_inclusions(docs の 設計/メニュー/表.md 1.3)。
 */
#[Fillable(['menu_group_id', 'menu_group_category_id'])]
class MenuGroupCategoryInclusion extends Model
{
    /** @use HasFactory<MenuGroupCategoryInclusionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<MenuGroup, $this>
     */
    public function menuGroup(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class);
    }

    /**
     * @return BelongsTo<MenuGroupCategory, $this>
     */
    public function menuGroupCategory(): BelongsTo
    {
        return $this->belongsTo(MenuGroupCategory::class);
    }
}

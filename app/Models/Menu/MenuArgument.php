<?php

namespace App\Models\Menu;

use Database\Factories\Menu\MenuArgumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * メニューの引数(連動する子。1 つのメニューの 1 つの引数名に値は 1 つ)。表 menu_arguments(docs の 設計/メニュー/表.md 1.5)。
 */
#[Fillable(['menu_id', 'name', 'value'])]
class MenuArgument extends Model
{
    /** @use HasFactory<MenuArgumentFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }
}

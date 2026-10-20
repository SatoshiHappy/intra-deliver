<?php

namespace App\Models\Menu;

use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;
use Database\Factories\Menu\MenuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * メニュー(フォルダ・アイテム・外部メニューリンクの親子。親の無い行はメニューグループのルート)。表 menus(docs の 設計/メニュー/表.md 1.4)。
 * 属するグループは親をたどったルートで決まる(列に持たない)。
 */
#[Fillable(['code', 'parent_id', 'sort_number', 'type', 'url', 'image_path', 'original_menu_id', 'method', 'is_iframe_used', 'is_popup_used', 'icon_16', 'icon_32', 'icon_48', 'notes'])]
class Menu extends Model
{
    /** @use HasFactory<MenuFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_number' => 'integer',
            'type' => MenuType::class,
            'method' => MenuMethod::class,
            'is_iframe_used' => 'boolean',
            'is_popup_used' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Menu, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    /**
     * @return HasMany<Menu, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * コピー元のメニュー(コピーで作っていなければ null)。
     *
     * @return BelongsTo<Menu, $this>
     */
    public function originalMenu(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_menu_id');
    }

    /**
     * このメニューをコピー元にしたメニュー。
     *
     * @return HasMany<Menu, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(self::class, 'original_menu_id');
    }

    /**
     * @return HasMany<MenuTranslation, $this>
     */
    public function menuTranslations(): HasMany
    {
        return $this->hasMany(MenuTranslation::class);
    }

    /**
     * @return HasMany<MenuArgument, $this>
     */
    public function menuArguments(): HasMany
    {
        return $this->hasMany(MenuArgument::class);
    }

    /**
     * このメニューをルートに持つメニューグループ(ルートでなければ null)。
     *
     * @return HasOne<MenuGroup, $this>
     */
    public function menuGroup(): HasOne
    {
        return $this->hasOne(MenuGroup::class);
    }
}

<?php

namespace Tests\Support;

use App\Models\Menu\Menu;
use App\Models\Menu\MenuArgument;
use App\Models\Menu\MenuGroup;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Menu\MenuGroupCategoryInclusion;
use App\Models\Menu\MenuGroupCategoryTranslation;
use App\Models\Menu\MenuTranslation;
use Illuminate\Database\Eloquent\Model;

/**
 * メニューの 7 表の設計どおりの列(docs の 設計/メニュー/表.md 1 節。型は 設計/共通マスタ/共通の規則.md 4 節)。書き方は {@see CommonMasterSchema} と同じ
 * (列 → PostgreSQL の format_type と NOT NULL・既定値。id・created_at・updated_at は SchemaTest が足す)。
 */
final class MenuSchema
{
    private const CODE = 'character varying(100) not null';

    private const SORT_NUMBER = "bigint not null default '0'::bigint";

    private const FLAG = 'boolean not null default false';

    private const KEY = 'bigint not null';

    private const NAME = 'character varying(1000) not null';

    private const TEXT_1000 = 'character varying(1000)';

    /** アイコン ID(汎用属性のキーと同じ長さ) */
    private const ICON = 'character varying(100)';

    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'menu_group_categories' => MenuGroupCategory::class,
        'menu_group_category_translations' => MenuGroupCategoryTranslation::class,
        'menu_groups' => MenuGroup::class,
        'menu_group_category_inclusions' => MenuGroupCategoryInclusion::class,
        'menus' => Menu::class,
        'menu_translations' => MenuTranslation::class,
        'menu_arguments' => MenuArgument::class,
    ];

    /**
     * 外部キー(書き方は tests/Feature/Models/ForeignKeysTest.php の FOREIGN_KEYS と同じ: 参照する側のモデル、factory の状態、列、参照先の表と列、
     * 削除の動作、参照する側のリレーション、参照先の側のリレーション)。参照先のモジュールから参照する側のモジュールへ依存できないとき・
     * 同じ向きのリレーションが別の行にあるときは参照先の側のリレーションを null にする。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string, string|null}>
     */
    public const FOREIGN_KEYS = [
        [MenuGroupCategoryTranslation::class, null, ['menu_group_category_id'], 'menu_group_categories', ['id'], 'cascade', 'menuGroupCategory', 'menuGroupCategoryTranslations'],
        [MenuGroupCategoryTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', null],
        [MenuGroup::class, null, ['menu_id'], 'menus', ['id'], 'restrict', 'menu', 'menuGroup'],
        [MenuGroupCategoryInclusion::class, null, ['menu_group_id'], 'menu_groups', ['id'], 'restrict', 'menuGroup', 'menuGroupCategoryInclusions'],
        [MenuGroupCategoryInclusion::class, null, ['menu_group_category_id'], 'menu_group_categories', ['id'], 'restrict', 'menuGroupCategory', 'menuGroupCategoryInclusions'],
        [Menu::class, 'item', ['parent_id'], 'menus', ['id'], 'restrict', 'parent', 'children'],
        [Menu::class, 'copied', ['original_menu_id'], 'menus', ['id'], 'restrict', 'originalMenu', 'copies'],
        [MenuTranslation::class, null, ['menu_id'], 'menus', ['id'], 'cascade', 'menu', 'menuTranslations'],
        [MenuTranslation::class, null, ['locale_id'], 'locales', ['id'], 'restrict', 'locale', null],
        [MenuArgument::class, null, ['menu_id'], 'menus', ['id'], 'cascade', 'menu', 'menuArguments'],
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'menu_group_categories' => ['code' => self::CODE, 'is_selectable' => self::FLAG, 'is_editable' => self::FLAG],
        'menu_group_category_translations' => ['menu_group_category_id' => self::KEY, 'locale_id' => self::KEY, 'display_name' => self::NAME],
        'menu_groups' => ['menu_id' => self::KEY],
        'menu_group_category_inclusions' => ['menu_group_id' => self::KEY, 'menu_group_category_id' => self::KEY],
        'menus' => [
            'code' => self::CODE,
            'parent_id' => 'bigint',
            'sort_number' => self::SORT_NUMBER,
            'type' => 'character varying(20) not null',
            'url' => self::TEXT_1000,
            'image_path' => self::TEXT_1000,
            'original_menu_id' => 'bigint',
            'method' => "character varying(20) not null default 'get'::character varying",
            'is_iframe_used' => self::FLAG,
            'is_popup_used' => self::FLAG,
            'icon_16' => self::ICON,
            'icon_32' => self::ICON,
            'icon_48' => self::ICON,
            'notes' => 'character varying(4000)',
        ],
        'menu_translations' => ['menu_id' => self::KEY, 'locale_id' => self::KEY, 'display_name' => self::NAME],
        'menu_arguments' => ['menu_id' => self::KEY, 'name' => 'character varying(100) not null', 'value' => 'character varying(4000) not null'],
    ];
}

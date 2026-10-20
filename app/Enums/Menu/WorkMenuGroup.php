<?php

namespace App\Enums\Menu;

/**
 * グループ「業務」のグループ ID と、業務モジュールのアイテム・分類のフォルダ・分類の中のアイテムのメニュー ID の作り方(docs の 設計/メニュー/初期データ.md 4 節、
 * 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1、モジュール分類/README.md 1.1・1.2)。メニューの初期データと業務処理が同じ ID を使うための 1 か所。
 */
enum WorkMenuGroup: string
{
    /** 分類 ID の形(Laravel の Str::ulid() を小文字にした 26 文字 = モジュール分類/README.md 1.1) */
    public const CATEGORY_ID_PATTERN = '/^[0-9a-hjkmnp-tv-z]{26}$/D';

    /** グローバルナビ(PC 用)の「業務」 */
    case GlobalNavigation = 'global-nav-main';

    /** サイトマップ(PC 用)の「業務」 */
    case Sitemap = 'sitemap-main';

    /**
     * このグループの業務モジュールのアイテムのメニュー ID の接頭辞(<グループ ID>-records-)。
     */
    public function recordModuleItemPrefix(): string
    {
        return "{$this->value}-records-";
    }

    /**
     * このグループの業務モジュールのアイテムのメニュー ID(<グループ ID>-records-<業務モジュールのコード>)。
     */
    public function recordModuleItemCode(string $moduleCode): string
    {
        return $this->recordModuleItemPrefix().$moduleCode;
    }

    /**
     * 分類 ID の形か(モジュール分類/README.md 1.1)。
     */
    public static function isCategoryId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::CATEGORY_ID_PATTERN, $id) === 1;
    }

    /**
     * このグループの分類のフォルダのメニュー ID の接頭辞(<グループ ID>-cat-)。
     */
    public function categoryFolderPrefix(): string
    {
        return "{$this->value}-cat-";
    }

    /**
     * このグループの分類のフォルダのメニュー ID(<グループ ID>-cat-<分類 ID> = モジュール分類/README.md 1.2)。
     */
    public function categoryFolderCode(string $categoryId): string
    {
        return $this->categoryFolderPrefix().$categoryId;
    }

    /**
     * メニュー ID がこのグループの分類のフォルダの形なら、その分類 ID(形でなければ null)。
     */
    public function categoryIdOf(string $menuCode): ?string
    {
        $prefix = $this->categoryFolderPrefix();
        $categoryId = str_starts_with($menuCode, $prefix) ? substr($menuCode, strlen($prefix)) : null;

        return self::isCategoryId($categoryId) ? $categoryId : null;
    }

    /**
     * このグループの分類の中のアイテムのメニュー ID の接頭辞(<分類のフォルダのメニュー ID>-。業務モジュールのコードは - を含まないので、
     * 接頭辞の後の全部が業務モジュールのコード = モジュール分類/README.md 1.2)。
     */
    public function categoryItemPrefix(string $categoryId): string
    {
        return $this->categoryFolderCode($categoryId).'-';
    }

    /**
     * このグループの分類の中の業務モジュールのアイテムのメニュー ID(<分類のフォルダのメニュー ID>-<業務モジュールのコード>)。
     */
    public function categoryItemCode(string $categoryId, string $moduleCode): string
    {
        return $this->categoryItemPrefix($categoryId).$moduleCode;
    }

    /**
     * 業務モジュールの 2 つのアイテムのメニュー ID(グループの宣言の順 = グローバルナビ → サイトマップ)。
     *
     * @return list<string>
     */
    public static function recordModuleItemCodes(string $moduleCode): array
    {
        return array_map(fn (self $group) => $group->recordModuleItemCode($moduleCode), self::cases());
    }
}

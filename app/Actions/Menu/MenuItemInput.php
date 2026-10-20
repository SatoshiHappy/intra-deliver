<?php

namespace App\Actions\Menu;

use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;

/**
 * メニューアイテムを作る業務処理に渡す値(docs の 設計/メニュー/表.md 1.7: 本家「メニューアイテムでは全ての項目を使用します。」= すべての列と引数。
 * 表示名も受ける)。URL の形は業務処理が検査する。
 */
final readonly class MenuItemInput
{
    /**
     * @param  string  $code  メニュー ID
     * @param  string  $url  アプリの中のパスか、外部サイトの絶対 URL
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     * @param  int|null  $originalMenuId  コピー元のメニュー(コピーで作っていなければ null)
     * @param  array<string, string>  $arguments  引数名 → 引数値
     */
    public function __construct(
        public string $code,
        public string $url,
        public array $displayNames,
        public int $sortNumber = 0,
        public MenuMethod $method = MenuMethod::Get,
        public ?string $imagePath = null,
        public ?string $notes = null,
        public ?string $icon16 = null,
        public ?string $icon32 = null,
        public ?string $icon48 = null,
        public bool $isIframeUsed = false,
        public bool $isPopupUsed = false,
        public ?int $originalMenuId = null,
        public array $arguments = [],
    ) {}

    /**
     * menus の列の値(親を除く)。
     *
     * @return array<string, string|int|bool|null>
     */
    public function attributes(): array
    {
        return [
            'code' => $this->code,
            'sort_number' => $this->sortNumber,
            'type' => MenuType::Item->value,
            'url' => $this->url,
            'method' => $this->method->value,
            'image_path' => $this->imagePath,
            'notes' => $this->notes,
            'icon_16' => $this->icon16,
            'icon_32' => $this->icon32,
            'icon_48' => $this->icon48,
            'is_iframe_used' => $this->isIframeUsed,
            'is_popup_used' => $this->isPopupUsed,
            'original_menu_id' => $this->originalMenuId,
        ];
    }
}

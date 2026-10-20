<?php

namespace App\Actions\Menu;

use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;

/**
 * メニューフォルダ(グループのルートを含む)を作る業務処理に渡す値(docs の 設計/メニュー/表.md 1.7: 本家「メニューフォルダで使用されるのは」
 * メニュー ID・ソート番号・種別・画像ファイルパス・説明・アイコン ID。表示名も受ける)。URL は NULL、メソッドは既定の get で作る。
 */
final readonly class MenuFolderInput
{
    /**
     * @param  string  $code  メニュー ID(グループのルートならグループ ID)
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     */
    public function __construct(
        public string $code,
        public array $displayNames,
        public int $sortNumber = 0,
        public ?string $imagePath = null,
        public ?string $notes = null,
        public ?string $icon16 = null,
        public ?string $icon32 = null,
        public ?string $icon48 = null,
    ) {}

    /**
     * menus の列の値(親を除く)。
     *
     * @return array<string, string|int|null>
     */
    public function attributes(): array
    {
        return [
            'code' => $this->code,
            'sort_number' => $this->sortNumber,
            'type' => MenuType::Folder->value,
            'url' => null,
            'method' => MenuMethod::Get->value,
            'image_path' => $this->imagePath,
            'notes' => $this->notes,
            'icon_16' => $this->icon16,
            'icon_32' => $this->icon32,
            'icon_48' => $this->icon48,
        ];
    }
}

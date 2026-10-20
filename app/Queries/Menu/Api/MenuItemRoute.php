<?php

namespace App\Queries\Menu\Api;

/**
 * 表示判定でアイテムの URL に合ったアプリのルート({@see MenuItemVisibility::hiddenItems()} の入力の値。docs の
 * 設計/ワークフロー/申請の周辺/メニューと導線.md 1.2)。照合は表示判定の 1 か所が行い、実装はパスを読み直さない。
 */
final readonly class MenuItemRoute
{
    /**
     * @param  string  $routeName  ルートの名前
     * @param  array<string, mixed>  $parameters  ルートの引数(名前 → 値。パスから読んだ値は文字列)
     */
    public function __construct(
        public string $routeName,
        public array $parameters,
    ) {}
}

<?php

namespace App\Enums\Menu;

/**
 * メニューの種別(menus.type。本家の menu_type。docs の 設計/メニュー/表.md 1.4)。
 * Folder = メニューフォルダ(グループのルートもこれ)、Item = メニューアイテム、External = 外部メニューリンク(この段では作らず表示しない)。
 */
enum MenuType: string
{
    case Item = 'item';
    case Folder = 'folder';
    case External = 'external';
}

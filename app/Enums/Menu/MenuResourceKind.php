<?php

namespace App\Enums\Menu;

/**
 * メニューの認可のリソースの種類と、その ID・URI の作り方(docs の 設計/メニュー/認可との結び付け.md 2.2)。作り方はここだけに置き、
 * 同期する業務処理と初期データが使う。
 *
 * セット im-menu-group の下に、カテゴリ(im-menu-group-cat-<カテゴリ ID>、URI im-menu-group://category/<カテゴリ ID>)、
 * その下にメニューグループ(im-menu-group-<グループ ID>、URI im-menu-group://group/<グループ ID>)を置く。
 */
enum MenuResourceKind: string
{
    /** メニューグループカテゴリ(値は URI の区分) */
    case Category = 'category';

    /** メニューグループ(値は URI の区分) */
    case Group = 'group';

    /**
     * カテゴリ ID かグループ ID から、リソースグループ ID を作る。
     */
    public function resourceGroupCode(string $id): string
    {
        return match ($this) {
            self::Category => MenuGroupAction::SET_CODE.'-cat-'.$id,
            self::Group => MenuGroupAction::SET_CODE.'-'.$id,
        };
    }

    /**
     * カテゴリ ID かグループ ID から、リソース URI を作る。
     */
    public function uri(string $id): string
    {
        return MenuGroupAction::RESOURCE_TYPE.'://'.$this->value.'/'.$id;
    }
}

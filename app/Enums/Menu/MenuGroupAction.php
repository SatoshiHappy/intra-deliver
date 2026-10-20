<?php

namespace App\Enums\Menu;

/**
 * 認可のリソースタイプ「メニューグループ」(im-menu-group)のアクション(docs の 設計/メニュー/認可との結び付け.md 2.1。ID は本家のまま)。
 * カテゴリとメニューグループのリソースがこのタイプを使う。
 */
enum MenuGroupAction: string
{
    /** リソースタイプの ID(本家のまま) */
    public const RESOURCE_TYPE = 'im-menu-group';

    /** このタイプのリソースを置くリソースグループセットの ID(本家の予約のセット ID) */
    public const SET_CODE = 'im-menu-group';

    /** 参照 */
    case Read = 'read';

    /** 管理 */
    case Admin = 'admin';
}

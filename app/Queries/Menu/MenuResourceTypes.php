<?php

namespace App\Queries\Menu;

use App\Enums\Menu\MenuGroupAction;
use App\Queries\Authz\ResourceTypes;

/**
 * Menu が定義するリソースタイプ: メニューグループ im-menu-group(アクションは {@see MenuGroupAction})。起動がタグ {@see ResourceTypes::TAG} で登録する
 * (docs の 設計/メニュー/認可との結び付け.md 2.1)。
 */
class MenuResourceTypes implements ResourceTypes
{
    public function actionsByResourceType(): array
    {
        return [MenuGroupAction::RESOURCE_TYPE => array_map(fn (MenuGroupAction $action) => $action->value, MenuGroupAction::cases())];
    }
}

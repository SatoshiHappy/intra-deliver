<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\ResourceTypes;

/**
 * Records が定義するリソースタイプ: 業務モジュール record-module(アクションは {@see RecordModuleAction})。起動がタグ {@see ResourceTypes::TAG} で登録する
 * (docs の 設計/業務データの入れ物/README.md の「コードのモジュール」、認可とメニュー/README.md 1 節)。
 */
class RecordModuleResourceTypes implements ResourceTypes
{
    public function actionsByResourceType(): array
    {
        return [RecordModuleAction::RESOURCE_TYPE => array_map(fn (RecordModuleAction $action) => $action->value, RecordModuleAction::cases())];
    }
}

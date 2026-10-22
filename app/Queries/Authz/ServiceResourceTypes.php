<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ServiceAction;
use App\Queries\Authz\Api\ResourceTypes;

/**
 * Authz が定義するリソースタイプ: 画面・処理 service(アクションは {@see ServiceAction})。起動がタグ {@see ResourceTypes::TAG} で登録する。
 */
class ServiceResourceTypes implements ResourceTypes
{
    public function actionsByResourceType(): array
    {
        return [ServiceAction::RESOURCE_TYPE => array_map(fn (ServiceAction $action) => $action->value, ServiceAction::cases())];
    }
}

<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowFlowAction;
use App\Queries\Authz\Api\ResourceTypes;

/**
 * Workflow が定義するリソースタイプ: ワークフローのフロー workflow-flow(アクションは {@see WorkflowFlowAction})。
 * 起動がタグ {@see ResourceTypes::TAG} で登録する(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 3 節)。
 */
class WorkflowFlowResourceTypes implements ResourceTypes
{
    public function actionsByResourceType(): array
    {
        $actions = array_map(fn (WorkflowFlowAction $action) => $action->value, WorkflowFlowAction::cases());

        return [WorkflowFlowAction::RESOURCE_TYPE => $actions];
    }
}

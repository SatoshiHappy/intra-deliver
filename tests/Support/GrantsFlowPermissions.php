<?php

namespace Tests\Support;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;

/**
 * テストの中で、利用者 1 人にフローの workflow-flow のアクションを許すポリシーを足す手助け(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 3 節。
 * 初期データはシステム管理者だけに許す)。フローのリソースは {@see RunsWorkflowOperations::createFlow()} が作ったもの。
 */
trait GrantsFlowPermissions
{
    protected function grantFlow(User $user, string $flowCode, WorkflowFlowAction $action): void
    {
        $group = AuthzResourceGroup::query()->where('code', WorkflowFlowAction::resourceGroupCode($flowCode))->sole();
        $condition = new SubjectConditionInput(userId: $user->id);
        $subject = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, [])->id;
        $type = WorkflowFlowAction::RESOURCE_TYPE;

        app(CreatePolicy::class)->execute($group->id, $subject, $type, $action->value, PolicyEffect::Permit);
    }
}

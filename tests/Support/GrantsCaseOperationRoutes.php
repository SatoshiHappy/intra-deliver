<?php

namespace Tests\Support;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;

/**
 * テストの中で、利用者 1 人に申請操作の 6 ルートの execute を許すポリシーを足す手助け(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節・6 節の
 * 「ルートの認可と業務の判定の 2 段」。初期データはポリシーを置かず、システム管理者だけが先頭の許可を継承する)。許可は接頭辞のリソースグループに置く。
 */
trait GrantsCaseOperationRoutes
{
    protected function grantCaseOperationRoutes(User $user): void
    {
        $group = AuthzResourceGroup::query()->where('code', 'http-services.workflow.admin.cases')->sole();
        $condition = new SubjectConditionInput(userId: $user->id);
        $subject = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, [])->id;
        $type = ServiceAction::RESOURCE_TYPE;
        $action = ServiceAction::Execute->value;

        app(CreatePolicy::class)->execute($group->id, $subject, $type, $action, PolicyEffect::Permit);
    }
}

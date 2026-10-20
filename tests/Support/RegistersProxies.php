<?php

namespace Tests\Support;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowProxy;

/**
 * 代理のテストの手助け(docs の 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.2 節。代理の行はテストで作る)と、
 * 業務モジュールのアクションを 1 人だけに許すポリシー(14.3 節の「テストの中でポリシーを足して許可・禁止を作る」)。
 */
trait RegistersProxies
{
    /**
     * 代理の行(登録した人 = 代理元)。期間は [start, end)(end = 行の end_date。null = 上限なし)。
     */
    protected function addProxy(
        User $principal,
        User $proxy,
        WorkflowProxyKind $kind = WorkflowProxyKind::Approve,
        ?int $flowId = null,
        string $start = '2026-01-01',
        ?string $end = null,
    ): WorkflowProxy {
        return WorkflowProxy::factory()->create([
            'principal_user_id' => $principal->id,
            'proxy_user_id' => $proxy->id,
            'kind' => $kind,
            'workflow_flow_id' => $flowId,
            'start_date' => $start,
            'end_date' => $end,
            'registered_by_user_id' => $principal->id,
        ]);
    }

    protected function flowIdOf(string $code): int
    {
        return WorkflowFlow::query()->where('code', $code)->sole()->id;
    }

    /**
     * 業務モジュールのアクションを、渡した人だけに許す(初期ポリシーのうち、そのモジュールのそのアクションの許可を消し、
     * 1 人ずつの対象者のポリシーを足す。業務モジュールの組の初期ポリシー = システム管理者の許可は残る)。
     */
    protected function allowOnly(string $moduleCode, RecordModuleAction $action, User ...$users): void
    {
        $code = RecordModuleAction::resourceGroupCode($moduleCode);
        $groupId = AuthzResourceGroup::query()->where('code', $code)->sole()->id;
        AuthzPolicy::query()->where('authz_resource_group_id', $groupId)->where('action', $action->value)->delete();

        foreach ($users as $user) {
            $condition = new SubjectConditionInput(userId: $user->id);
            $subjectId = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, [])->id;
            $type = RecordModuleAction::RESOURCE_TYPE;
            app(CreatePolicy::class)->execute($groupId, $subjectId, $type, $action->value, PolicyEffect::Permit);
        }
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\Role;
use App\Models\Authz\UserRole;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordModuleVisibilityStops;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;

/**
 * 道具の判定のテストの準備: 依頼者(ロールを 1 つ持つ)・ロールにだけ操作を許す業務モジュール・途中で立てて消せる表示の止め・会話の型の実行。
 */
trait ArrangesToolPermissions
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private User $requester;

    private Role $role;

    private UserRole $grantedRole;

    private TestVisibilityStops $stops;

    private function arrangeRequester(): void
    {
        $this->requester = $this->user();
        $this->role = Role::factory()->create();
        $this->grantedRole = $this->grant($this->requester, $this->role);
        $this->stops = new TestVisibilityStops;
        $this->app->instance(TestVisibilityStops::class, $this->stops);
        $this->app->tag([TestVisibilityStops::class], RecordModuleVisibilityStops::TAG);
    }

    /**
     * 誰にも許さない業務モジュールを作り、依頼者のロールにだけ渡した操作を許す。
     *
     * @param  list<RecordModuleAction>  $actions
     */
    private function moduleFor(string $code, array $actions): RecordModule
    {
        $module = $this->recordModule($code, []);
        $this->permit($code, $actions);

        return $module;
    }

    /**
     * 業務モジュールの操作を許す(既定は依頼者のロールに)。
     *
     * @param  list<RecordModuleAction>  $actions
     */
    private function permit(string $code, array $actions, ?SubjectConditionInput $condition = null): void
    {
        $resource = AuthzResource::query()->where('uri', RecordModuleAction::uri($code));
        $groupId = (int) $resource->value('authz_resource_group_id');
        $condition ??= new SubjectConditionInput(roleId: $this->role->id);
        $subjectGroupId = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, [])->id;
        $type = RecordModuleAction::RESOURCE_TYPE;

        foreach ($actions as $action) {
            app(CreatePolicy::class)->execute($groupId, $subjectGroupId, $type, $action->value, PolicyEffect::Permit);
        }
    }

    /** 依頼者に 2 つ目のロールを持たせ、そのロールに業務モジュールの操作を許す。外すときは返した割り当てを消す */
    private function secondRole(string $code, RecordModuleAction $action): UserRole
    {
        $role = Role::factory()->create();
        $this->permit($code, [$action], new SubjectConditionInput(roleId: $role->id));

        return $this->grant($this->requester, $role);
    }

    /** 依頼者を組織に主所属で入れ、その組織に業務モジュールの操作を許す。外すときは返した所属を消す */
    private function departmentGrant(string $code, RecordModuleAction $action): DepartmentMembership
    {
        $department = $this->department(DepartmentSet::query()->where('is_default', true)->firstOrFail());
        $this->permit($code, [$action], new SubjectConditionInput(departmentId: $department->id));

        return $this->join($this->requester, $department, isMain: true);
    }

    /** 依頼者からロールを外す */
    private function revokeRole(): void
    {
        $this->grantedRole->update(['is_deleted' => true]);
    }

    /** 依頼者を無効にする(期間の行を消した印にする) */
    private function deactivateRequester(): void
    {
        UserTerm::query()->where('user_id', $this->requester->id)->update(['is_deleted' => true]);
    }

    private function stop(RecordModule $module): void
    {
        $this->stops->reasons[$module->id] = '止め';
    }

    /**
     * 依頼者の会話の型の実行(定義の道具とナレッジ)。
     *
     * @param  list<string>  $tools
     * @param  list<string>  $knowledge
     */
    private function runFor(array $tools, array $knowledge): AiRun
    {
        $definition = AiAgentDefinition::factory()->create(['tools' => $tools, 'knowledge' => $knowledge]);

        return AiRun::factory()->conversation()->create([
            'requester_user_id' => $this->requester->id,
            'agent_definition_id' => $definition->id,
        ]);
    }
}

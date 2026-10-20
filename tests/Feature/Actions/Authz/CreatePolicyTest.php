<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreatePolicy;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のポリシーを作る(docs の 設計/メニュー/認可との結び付け.md 2.4、設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies: (リソースタイプ, アクション)の組が列挙にある。
 * リソースを持たないリソースグループにも置ける。用途 selection の対象者は指さない = 設計/共通マスタ/ロールと認可/対象者.md の「用途 `selection` の規則」)。
 */
final class CreatePolicyTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_creates_a_policy_for_a_registered_pair_on_a_group_without_a_resource(): void
    {
        $resourceGroup = AuthzResourceGroup::factory()->create();
        $subjectGroup = AuthzSubjectGroup::factory()->create();

        $policy = $this->create($resourceGroup, $subjectGroup, 'service', 'execute');

        $this->assertSame(
            [$resourceGroup->id, $subjectGroup->id, 'service', 'execute', PolicyEffect::Permit],
            [$policy->authz_resource_group_id, $policy->authz_subject_group_id, $policy->resource_type, $policy->action, $policy->effect],
        );
    }

    public function test_rejects_an_action_missing_from_the_actions_of_the_resource_type(): void
    {
        $policies = AuthzPolicy::query()->count();

        $this->assertRejected('action', fn () => $this->create(AuthzResourceGroup::factory()->create(), AuthzSubjectGroup::factory()->create(), 'service', 'read'));
        $this->assertSame($policies, AuthzPolicy::query()->count());
    }

    public function test_rejects_a_resource_type_missing_from_the_registered_types(): void
    {
        $policies = AuthzPolicy::query()->count();

        $this->assertRejected('action', fn () => $this->create(AuthzResourceGroup::factory()->create(), AuthzSubjectGroup::factory()->create(), 'no-such-type', 'execute'));
        $this->assertSame($policies, AuthzPolicy::query()->count());
    }

    public function test_rejects_a_subject_group_for_selection(): void
    {
        $selection = AuthzSubjectGroup::factory()->create(['purpose' => SubjectGroupPurpose::Selection]);
        $policies = AuthzPolicy::query()->count();

        $this->assertRejected('authz_subject_group_id', fn () => $this->create(AuthzResourceGroup::factory()->create(), $selection, 'service', 'execute'));
        $this->assertSame($policies, AuthzPolicy::query()->count());
    }

    private function create(AuthzResourceGroup $resourceGroup, AuthzSubjectGroup $subjectGroup, string $resourceType, string $action): AuthzPolicy
    {
        return app(CreatePolicy::class)->execute($resourceGroup->id, $subjectGroup->id, $resourceType, $action, PolicyEffect::Permit);
    }
}

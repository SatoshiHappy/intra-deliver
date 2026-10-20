<?php

namespace Tests\Feature\Models\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のポリシー(docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md: エフェクトは permit か deny、リソースグループ・リソースタイプ・アクション・対象者の組に 1 つ)。
 */
final class AuthzPolicyTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{PolicyEffect}>
     */
    public static function effects(): array
    {
        return ['permit' => [PolicyEffect::Permit], 'deny' => [PolicyEffect::Deny]];
    }

    #[DataProvider('effects')]
    public function test_reads_back_the_effect_as_the_enum(PolicyEffect $effect): void
    {
        $policy = AuthzPolicy::factory()->create(['effect' => $effect]);

        $this->assertSame($effect, $policy->refresh()->effect);
    }

    public function test_rejects_an_effect_outside_the_list(): void
    {
        $row = [...AuthzPolicy::factory()->makeOne()->getAttributes(), 'effect' => 'unset'];

        $this->assertRejectedBy('authz_policies_effect_check', fn () => DB::table('authz_policies')->insert($row));
    }

    public function test_rejects_a_second_policy_for_the_same_group_resource_type_action_and_subject(): void
    {
        $existing = AuthzPolicy::factory()->create();
        $sameTarget = [...$existing->only(['authz_resource_group_id', 'resource_type', 'action', 'authz_subject_group_id']), 'effect' => PolicyEffect::Deny];

        $this->assertRejectedBy(
            'authz_policies_authz_resource_group_id_resource_type_action_authz_subject_group_id_unique',
            fn () => AuthzPolicy::factory()->create($sameTarget),
        );
    }

    public function test_accepts_another_action_for_the_same_group_resource_type_and_subject(): void
    {
        $existing = AuthzPolicy::factory()->create(['resource_type' => 'im-menu-group', 'action' => 'read']);

        $other = AuthzPolicy::factory()->create([...$existing->only(['authz_resource_group_id', 'resource_type', 'authz_subject_group_id']), 'action' => 'admin']);

        $this->assertModelExists($other);
    }

    public function test_accepts_the_same_action_of_another_resource_type_for_the_same_group_and_subject(): void
    {
        $existing = AuthzPolicy::factory()->create(['resource_type' => 'im-menu-group', 'action' => 'execute']);

        $other = AuthzPolicy::factory()->create([...$existing->only(['authz_resource_group_id', 'action', 'authz_subject_group_id']), 'resource_type' => 'service']);

        $this->assertModelExists($other);
    }
}

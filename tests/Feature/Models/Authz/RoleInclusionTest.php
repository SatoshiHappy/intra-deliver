<?php

namespace Tests\Feature\Models\Authz;

use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * サブロール(docs の 設計/共通マスタ/ロールと認可/ロール.md 2 節: 同じ親子は 1 つ、自分を子にしない、1 つのロールが複数の親を持てる)。
 */
final class RoleInclusionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_role_as_its_own_sub_role(): void
    {
        $role = Role::factory()->create();

        $this->assertRejectedBy(
            'role_inclusions_parent_role_id_child_role_id_check',
            fn () => RoleInclusion::factory()->create(['parent_role_id' => $role->id, 'child_role_id' => $role->id]),
        );
    }

    public function test_rejects_the_same_parent_and_child_twice(): void
    {
        $existing = RoleInclusion::factory()->create();
        $samePair = $existing->only(['parent_role_id', 'child_role_id']);

        $this->assertRejectedBy('role_inclusions_parent_role_id_child_role_id_unique', fn () => RoleInclusion::factory()->create($samePair));
    }

    public function test_accepts_several_parents_for_one_sub_role(): void
    {
        $existing = RoleInclusion::factory()->create();

        $other = RoleInclusion::factory()->create(['child_role_id' => $existing->child_role_id]);

        $this->assertModelExists($other);
    }
}

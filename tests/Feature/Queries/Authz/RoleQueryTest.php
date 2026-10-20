<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Models\Master\User;
use App\Queries\Authz\RoleQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーが d に持つロール(docs の 設計/共通マスタ/ロールと認可/ロール.md 2 節・3 節: d に成り立つ付与のロールと、
 * そこからサブロールを親から子へたどったロール。付与が成り立つ = 付与の行とユーザーが d に有効 = 共通の規則 5.4)。
 */
final class RoleQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_a_parent_role_brings_its_sub_roles_but_not_the_other_way(): void
    {
        $parent = Role::factory()->create();
        $child = Role::factory()->create();
        $grandchild = Role::factory()->create();
        $this->subRole($parent, $child);
        $this->subRole($child, $grandchild);
        $holderOfParent = $this->user();
        $holderOfChild = $this->user();
        $this->grant($holderOfParent, $parent);
        $this->grant($holderOfChild, $child);

        $this->assertEqualsCanonicalizing([$parent->id, $child->id, $grandchild->id], $this->roleIdsOn($holderOfParent, '2024-04-01'));
        $this->assertEqualsCanonicalizing([$child->id, $grandchild->id], $this->roleIdsOn($holderOfChild, '2024-04-01'));
    }

    public function test_a_grant_holds_from_its_start_date_until_the_day_before_its_end_date(): void
    {
        $role = Role::factory()->create();
        $user = $this->user();
        $this->grant($user, $role, ['2024-04-01', '2025-04-01']);

        $this->assertSame([[], [$role->id], [$role->id], []], array_map(
            fn (string $date) => $this->roleIdsOn($user, $date),
            ['2024-03-31', '2024-04-01', '2025-03-31', '2025-04-01'],
        ));
    }

    public function test_a_deleted_grant_and_a_gap_between_grants_give_no_role(): void
    {
        $role = Role::factory()->create();
        $user = $this->user();
        $this->grant($user, $role, ['2020-01-01', '2024-04-01']);
        $this->grant($user, $role, ['2024-04-01', '2024-10-01', true]);
        $this->grant($user, $role, ['2025-01-01', null]);

        $this->assertSame([[$role->id], [], [], [$role->id]], array_map(
            fn (string $date) => $this->roleIdsOn($user, $date),
            ['2024-03-31', '2024-04-01', '2024-12-31', '2025-01-01'],
        ));
    }

    public function test_a_user_who_is_not_effective_has_no_role(): void
    {
        $role = Role::factory()->create();
        $user = $this->user([['2020-01-01', '2024-04-01'], ['2024-04-01', null, true]]);
        $this->grant($user, $role);

        $this->assertSame([$role->id], $this->roleIdsOn($user, '2024-03-31'));
        $this->assertSame([], $this->roleIdsOn($user, '2024-04-01'));
    }

    public function test_reads_only_the_roles_of_the_given_user(): void
    {
        $user = $this->user();
        $other = $this->user();
        $this->grant($other, Role::factory()->create());

        $this->assertSame([], $this->roleIdsOn($user, '2024-04-01'));
    }

    public function test_a_cycle_of_sub_roles_left_by_mistake_does_not_loop_forever(): void
    {
        $first = Role::factory()->create();
        $second = Role::factory()->create();
        $this->subRole($first, $second);
        $this->subRole($second, $first);
        $user = $this->user();
        $this->grant($user, $first);

        $this->assertEqualsCanonicalizing([$first->id, $second->id], $this->roleIdsOn($user, '2024-04-01'));
    }

    private function subRole(Role $parent, Role $child): void
    {
        RoleInclusion::factory()->create(['parent_role_id' => $parent->id, 'child_role_id' => $child->id]);
    }

    /**
     * @return array<int, int>
     */
    private function roleIdsOn(User $user, string $date): array
    {
        return app(RoleQuery::class)->rolesOn($user->id, self::day($date))->map(fn (Role $role) => $role->id)->all();
    }
}

<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 組織所属(docs の 設計/共通マスタ/組織とユーザー.md 8 節: 所属の組織は所属の組織セットのもの、兼務は数の上限なし、
 * 1 人について 1 つの組織セットの中で、有効な主所属は同時に 1 つまで(無効の所属は数えない))。
 * 期間の列と業務キーの排他は PeriodColumnsTest。
 */
final class DepartmentMembershipTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const SECOND_MAIN = 'department_memberships_user_id_department_set_id_start_date_end_date_excl';

    public function test_rejects_a_department_in_another_set(): void
    {
        $set = DepartmentSet::factory()->create();
        $departmentInOtherSet = Department::factory()->create();

        $this->assertRejectedBy(
            'department_memberships_department_set_id_department_id_foreign',
            fn () => DepartmentMembership::factory()->create(['department_set_id' => $set->id, 'department_id' => $departmentInOtherSet->id]),
        );
    }

    public function test_accepts_concurrent_memberships_in_several_departments(): void
    {
        [$first, $second] = $this->departmentsInOneSet();
        $user = User::factory()->create();
        $this->join($user, $first, ['is_main' => true]);

        $concurrent = $this->join($user, $second, ['is_main' => false]);

        $this->assertModelExists($concurrent);
    }

    public function test_rejects_a_second_active_main_membership_in_the_same_set(): void
    {
        [$first, $second] = $this->departmentsInOneSet();
        $user = User::factory()->create();
        $this->join($user, $first, ['is_main' => true, 'start_date' => '2020-01-01']);

        $this->assertRejectedBy(self::SECOND_MAIN, fn () => $this->join($user, $second, ['is_main' => true, 'start_date' => '2024-04-01']));
    }

    public function test_accepts_a_main_membership_in_each_set(): void
    {
        $user = User::factory()->create();
        $this->join($user, Department::factory()->create(), ['is_main' => true]);

        $other = $this->join($user, Department::factory()->create(), ['is_main' => true]);

        $this->assertModelExists($other);
    }

    public function test_accepts_a_main_membership_on_the_days_of_a_deleted_main_membership(): void
    {
        [$first, $second] = $this->departmentsInOneSet();
        $user = User::factory()->create();
        $this->join($user, $first, ['is_main' => true, 'end_date' => '2025-01-01', 'is_deleted' => true]);

        $main = $this->join($user, $second, ['is_main' => true]);

        $this->assertModelExists($main);
    }

    public function test_rejects_reactivating_a_main_membership_that_makes_a_second_main(): void
    {
        [$first, $second] = $this->departmentsInOneSet();
        $user = User::factory()->create();
        $deleted = $this->join($user, $first, ['is_main' => true, 'end_date' => '2025-01-01', 'is_deleted' => true]);
        $this->join($user, $second, ['is_main' => true]);

        $this->assertRejectedBy(self::SECOND_MAIN, fn () => $deleted->update(['is_deleted' => false]));
    }

    /**
     * 同じ組織セットの組織 2 つ。
     *
     * @return array<int, Department>
     */
    private function departmentsInOneSet(): array
    {
        return Department::factory()->count(2)->for(DepartmentSet::factory())->create()->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function join(User $user, Department $department, array $attributes): DepartmentMembership
    {
        return DepartmentMembership::factory()->create([
            'department_set_id' => $department->department_set_id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'start_date' => '2020-01-01',
            ...$attributes,
        ]);
    }
}

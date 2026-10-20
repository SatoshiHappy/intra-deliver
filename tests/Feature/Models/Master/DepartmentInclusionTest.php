<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentSet;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 内包(docs の 設計/共通マスタ/組織とユーザー.md 5 節: 親と子は同じ組織セット、自分を親にしない、
 * 有効な内包で、ある日に子の親は 1 つ(無効の内包は数えない))。期間の列と業務キーの排他は PeriodColumnsTest。
 */
final class DepartmentInclusionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const SECOND_PARENT = 'department_inclusions_department_id_start_date_end_date_excl';

    public function test_rejects_a_department_as_its_own_parent(): void
    {
        $department = Department::factory()->create();

        $this->assertRejectedBy(
            'department_inclusions_parent_department_id_department_id_check',
            fn () => $this->include($department, $department),
        );
    }

    public function test_rejects_a_parent_in_another_set(): void
    {
        $child = Department::factory()->create();
        $parentInOtherSet = Department::factory()->create();

        $this->assertRejectedBy(
            'department_inclusions_department_set_id_parent_department_id_foreign',
            fn () => DepartmentInclusion::factory()->create([
                'department_set_id' => $child->department_set_id,
                'parent_department_id' => $parentInOtherSet->id,
                'department_id' => $child->id,
            ]),
        );
    }

    public function test_rejects_a_child_in_another_set(): void
    {
        $parent = Department::factory()->create();
        $childInOtherSet = Department::factory()->create();

        $this->assertRejectedBy(
            'department_inclusions_department_set_id_department_id_foreign',
            fn () => DepartmentInclusion::factory()->create([
                'department_set_id' => $parent->department_set_id,
                'parent_department_id' => $parent->id,
                'department_id' => $childInOtherSet->id,
            ]),
        );
    }

    public function test_rejects_a_second_active_parent_on_overlapping_days(): void
    {
        [$firstParent, $secondParent, $child] = $this->departmentsInOneSet();
        $this->include($firstParent, $child, ['start_date' => '2020-01-01', 'end_date' => null]);

        $this->assertRejectedBy(self::SECOND_PARENT, fn () => $this->include($secondParent, $child, ['start_date' => '2024-04-01', 'end_date' => null]));
    }

    public function test_accepts_a_new_parent_on_the_days_of_a_deleted_inclusion(): void
    {
        [$firstParent, $secondParent, $child] = $this->departmentsInOneSet();
        $this->include($firstParent, $child, ['start_date' => '2020-01-01', 'end_date' => '2025-01-01', 'is_deleted' => true]);

        $inclusion = $this->include($secondParent, $child, ['start_date' => '2024-04-01', 'end_date' => null]);

        $this->assertModelExists($inclusion);
    }

    public function test_rejects_reactivating_an_inclusion_that_makes_a_second_parent(): void
    {
        [$firstParent, $secondParent, $child] = $this->departmentsInOneSet();
        $deleted = $this->include($firstParent, $child, ['start_date' => '2020-01-01', 'end_date' => '2025-01-01', 'is_deleted' => true]);
        $this->include($secondParent, $child, ['start_date' => '2024-04-01', 'end_date' => null]);

        $this->assertRejectedBy(self::SECOND_PARENT, fn () => $deleted->update(['is_deleted' => false]));
    }

    public function test_accepts_moving_a_child_to_another_parent_from_the_end_date(): void
    {
        [$firstParent, $secondParent, $child] = $this->departmentsInOneSet();
        $this->include($firstParent, $child, ['start_date' => '2020-01-01', 'end_date' => '2024-04-01']);

        $inclusion = $this->include($secondParent, $child, ['start_date' => '2024-04-01', 'end_date' => null]);

        $this->assertModelExists($inclusion);
    }

    /**
     * 同じ組織セットの組織 3 つ。
     *
     * @return array<int, Department>
     */
    private function departmentsInOneSet(): array
    {
        return Department::factory()->count(3)->for(DepartmentSet::factory())->create()->all();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function include(Department $parent, Department $child, array $attributes = []): DepartmentInclusion
    {
        return DepartmentInclusion::factory()->create([
            'department_set_id' => $child->department_set_id,
            'parent_department_id' => $parent->id,
            'department_id' => $child->id,
            ...$attributes,
        ]);
    }
}

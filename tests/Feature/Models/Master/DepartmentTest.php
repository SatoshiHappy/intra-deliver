<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\Department;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 組織のアンカー(docs の 設計/共通マスタ/組織とユーザー.md 4 節: 頂点の組織は 1 組織セットに 1 つまで)。
 */
final class DepartmentTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_second_root_in_the_same_set(): void
    {
        $root = Department::factory()->create(['is_root' => true]);

        $this->assertRejectedBy(
            'departments_department_set_id_unique',
            fn () => Department::factory()->create(['department_set_id' => $root->department_set_id, 'is_root' => true]),
        );
    }

    public function test_accepts_non_root_departments_beside_the_root(): void
    {
        $root = Department::factory()->create(['is_root' => true]);

        $other = Department::factory()->create(['department_set_id' => $root->department_set_id, 'is_root' => false]);

        $this->assertModelExists($other);
    }

    public function test_accepts_a_root_in_each_set(): void
    {
        Department::factory()->create(['is_root' => true]);

        $other = Department::factory()->create(['is_root' => true]);

        $this->assertModelExists($other);
    }
}

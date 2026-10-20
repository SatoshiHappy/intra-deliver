<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\DepartmentSet;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 組織セット(docs の 設計/共通マスタ/組織とユーザー.md 3 節: 既定の組織セットは 1 社に 1 つまで)。
 */
final class DepartmentSetTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_second_default_set_in_the_same_company(): void
    {
        $default = DepartmentSet::factory()->create(['is_default' => true]);

        $this->assertRejectedBy(
            'department_sets_company_id_unique',
            fn () => DepartmentSet::factory()->create(['company_id' => $default->company_id, 'is_default' => true]),
        );
    }

    public function test_accepts_non_default_sets_beside_the_default_set(): void
    {
        $default = DepartmentSet::factory()->create(['is_default' => true]);

        $other = DepartmentSet::factory()->create(['company_id' => $default->company_id, 'is_default' => false]);

        $this->assertModelExists($other);
    }

    public function test_accepts_a_default_set_in_each_company(): void
    {
        DepartmentSet::factory()->create(['is_default' => true]);

        $other = DepartmentSet::factory()->create(['is_default' => true]);

        $this->assertModelExists($other);
    }
}

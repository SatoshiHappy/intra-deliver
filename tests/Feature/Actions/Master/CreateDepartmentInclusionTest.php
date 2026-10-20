<?php

namespace Tests\Feature\Actions\Master;

use App\Actions\Master\CreateDepartmentInclusion;
use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentSet;
use App\Support\Period;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 内包を作る(docs の 設計/共通マスタ/組織とユーザー.md 5 節の業務処理で止める規則: 頂点は子にならない、
 * 内包が d に有効なら親子の組織も d に有効(共通の規則 5.5)、どの日にも有効な内包で循環を作らない)。
 */
final class CreateDepartmentInclusionTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use BuildsCommonMaster;

    private DepartmentSet $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create();
    }

    public function test_creates_an_effective_inclusion_in_the_set_of_the_departments(): void
    {
        [$parent, $child] = [$this->department($this->set), $this->department($this->set)];

        $inclusion = $this->create($parent, $child, '2024-04-01', '2025-04-01', sortKey: 2);

        $this->assertSame([$this->set->id, '2024-04-01', '2025-04-01', false, 2], [
            $inclusion->department_set_id, $inclusion->start_date->toDateString(), $inclusion->end_date?->toDateString(), $inclusion->is_deleted, $inclusion->sort_key,
        ]);
    }

    public function test_rejects_the_root_as_a_child(): void
    {
        $root = $this->department($this->set, isRoot: true);

        $this->assertRejected('department_id', fn () => $this->create($this->department($this->set), $root));
    }

    public function test_rejects_a_parent_that_is_not_effective_over_the_whole_period(): void
    {
        $child = $this->department($this->set);
        $startsLater = $this->department($this->set, [['2024-05-01', null]]);
        $deletedInside = $this->department($this->set, [['2020-01-01', '2024-06-01'], ['2024-06-01', '2024-07-01', true], ['2024-07-01', null]]);
        $endsBefore = $this->department($this->set, [['2020-01-01', '2030-01-01']]);

        $this->assertRejected('parent_department_id', fn () => $this->create($startsLater, $child, '2024-04-01'));
        $this->assertRejected('parent_department_id', fn () => $this->create($deletedInside, $child, '2024-04-01', '2025-04-01'));
        $this->assertRejected('parent_department_id', fn () => $this->create($endsBefore, $child, '2024-04-01'));
    }

    public function test_rejects_a_child_that_is_not_effective_over_the_whole_period(): void
    {
        $parent = $this->department($this->set);

        $this->assertRejected('department_id', fn () => $this->create($parent, $this->department($this->set, [['2024-05-01', null]]), '2024-04-01'));
    }

    public function test_accepts_a_department_effective_over_several_adjacent_terms(): void
    {
        $parent = $this->department($this->set, [['2020-01-01', '2024-06-01'], ['2024-06-01', null]]);

        $this->assertSame($parent->id, $this->create($parent, $this->department($this->set), '2024-04-01')->parent_department_id);
    }

    public function test_rejects_an_inclusion_that_closes_a_cycle(): void
    {
        [$a, $b, $c] = [$this->department($this->set), $this->department($this->set), $this->department($this->set)];
        $this->include($a, $b);
        $this->include($b, $c);

        $this->assertRejected('parent_department_id', fn () => $this->create($b, $a));
        $this->assertRejected('parent_department_id', fn () => $this->create($c, $a));
    }

    public function test_rejects_a_cycle_that_appears_only_later_in_the_period(): void
    {
        [$a, $b] = [$this->department($this->set), $this->department($this->set)];
        $this->include($a, $b, ['2025-04-01', null]);

        $this->assertRejected('parent_department_id', fn () => $this->create($b, $a, '2024-04-01'));
    }

    public function test_inclusions_on_different_days_or_deleted_ones_do_not_make_a_cycle(): void
    {
        [$a, $b, $c] = [$this->department($this->set), $this->department($this->set), $this->department($this->set)];
        $this->include($a, $b, ['2020-01-01', '2024-04-01']);
        $this->include($a, $c, ['2020-01-01', null, true]);

        $this->assertSame($b->id, $this->create($b, $a, '2024-04-01')->parent_department_id);
        $this->assertSame($c->id, $this->create($c, $a, '2020-01-01', '2024-04-01')->parent_department_id);
    }

    private function create(Department $parent, Department $child, string $start = '2020-01-01', ?string $end = null, int $sortKey = 0): DepartmentInclusion
    {
        return app(CreateDepartmentInclusion::class)->execute($parent->id, $child->id, new Period(self::day($start), $end === null ? null : self::day($end)), $sortKey);
    }
}

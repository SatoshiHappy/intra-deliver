<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentSet;
use App\Queries\Master\DepartmentTreeQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 組織ツリーと上位・下位の組織(docs の 設計/共通マスタ/組織とユーザー.md 5 節・9 節: d に成り立つ内包だけをたどる。
 * 内包が成り立つ = 内包の行と親子の組織が d に有効 = 共通の規則 5.4)。
 */
final class DepartmentTreeQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private DepartmentSet $set;

    private Department $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create();
        $this->root = $this->department($this->set, isRoot: true);
    }

    public function test_reads_all_upper_and_lower_departments_over_several_levels(): void
    {
        [$a, $b, $c] = $this->chainUnderRoot(3);

        $this->assertSame([$this->root->id, $a->id, $b->id], $this->ancestorIds($c, '2024-04-01'));
        $this->assertSame([$b->id, $c->id], $this->descendantIds($a, '2024-04-01'));
        $this->assertSame([], $this->ancestorIds($this->root, '2024-04-01'));
        $this->assertSame([], $this->descendantIds($c, '2024-04-01'));
    }

    public function test_an_inclusion_holds_from_its_start_date_until_the_day_before_its_end_date(): void
    {
        $child = $this->department($this->set);
        $this->include($this->root, $child, ['2024-04-01', '2025-04-01']);

        $this->assertSame([[], [$this->root->id], [$this->root->id], []], array_map(
            fn (string $date) => $this->ancestorIds($child, $date),
            ['2024-03-31', '2024-04-01', '2025-03-31', '2025-04-01'],
        ));
    }

    public function test_a_deleted_inclusion_cuts_the_tree(): void
    {
        [$a, $b, $c] = $this->chainUnderRoot(3);
        DepartmentInclusion::query()->where('department_id', $b->id)->update(['is_deleted' => true]);

        $this->assertSame([$b->id], $this->ancestorIds($c, '2024-04-01'));
        $this->assertSame([], $this->descendantIds($a, '2024-04-01'));
    }

    public function test_no_inclusion_holds_in_a_gap_between_inclusion_rows(): void
    {
        $child = $this->department($this->set);
        $this->include($this->root, $child, ['2020-01-01', '2024-04-01']);
        $this->include($this->root, $child, ['2024-10-01', null]);

        $this->assertSame([[$this->root->id], [], [$this->root->id]], array_map(
            fn (string $date) => $this->ancestorIds($child, $date),
            ['2024-03-31', '2024-04-01', '2024-10-01'],
        ));
    }

    public function test_an_inclusion_does_not_hold_when_one_of_its_departments_is_not_effective(): void
    {
        $a = $this->department($this->set);
        $b = $this->department($this->set, [['2020-01-01', '2024-04-01'], ['2024-04-01', null, true]]);
        $c = $this->department($this->set);
        $this->include($this->root, $a);
        $this->include($a, $b);
        $this->include($b, $c);

        $this->assertSame([$this->root->id, $a->id, $b->id], $this->ancestorIds($c, '2024-03-31'));
        $this->assertSame([], $this->ancestorIds($c, '2024-04-01'));
        $this->assertSame([], $this->descendantIds($a, '2024-04-01'));
    }

    public function test_follows_a_department_moved_to_another_parent(): void
    {
        [$a, $b] = $this->chainUnderRoot(2, ['2020-01-01', '2024-04-01']);
        $other = $this->department($this->set);
        $this->include($this->root, $other);
        $this->include($other, $b, ['2024-04-01', null]);
        $this->include($this->root, $a, ['2024-04-01', null]);

        $this->assertSame([$this->root->id, $a->id], $this->ancestorIds($b, '2024-03-31'));
        $this->assertSame([$this->root->id, $other->id], $this->ancestorIds($b, '2024-04-01'));
    }

    public function test_the_tree_holds_the_departments_reachable_from_the_root_with_their_parents(): void
    {
        [$a, $b] = $this->chainUnderRoot(2);
        $detachedParent = $this->department($this->set);
        $detachedChild = $this->department($this->set);
        $this->include($detachedParent, $detachedChild);

        $tree = app(DepartmentTreeQuery::class)->tree($this->set->id, self::day('2024-04-01'));

        $this->assertEqualsCanonicalizing([$this->root->id, $a->id, $b->id], $tree->modelKeys());
        $parents = $tree->mapWithKeys(fn (Department $department) => [$department->id => $department->inclusionsAsChild->pluck('parent_department_id')->all()]);
        $this->assertSame([$this->root->id => [], $a->id => [$this->root->id], $b->id => [$a->id]], $parents->sortKeys()->all());
        $this->assertSame([$detachedParent->id], $this->ancestorIds($detachedChild, '2024-04-01'));
    }

    public function test_there_is_no_tree_when_the_root_is_not_effective(): void
    {
        $this->chainUnderRoot(1);
        $this->root->departmentTerms()->update(['is_deleted' => true]);

        $this->assertTrue(app(DepartmentTreeQuery::class)->tree($this->set->id, self::day('2024-04-01'))->isEmpty());
    }

    public function test_the_tree_is_the_root_of_the_given_set(): void
    {
        [$a] = $this->chainUnderRoot(1);
        $otherSet = DepartmentSet::factory()->create();
        $otherRoot = $this->department($otherSet, isRoot: true);

        $this->assertEqualsCanonicalizing([$this->root->id, $a->id], app(DepartmentTreeQuery::class)->tree($this->set->id, self::day('2024-04-01'))->modelKeys());
        $this->assertSame([$otherRoot->id], app(DepartmentTreeQuery::class)->tree($otherSet->id, self::day('2024-04-01'))->modelKeys());
    }

    public function test_a_cycle_left_by_mistake_does_not_loop_forever_nor_count_the_department_itself(): void
    {
        $first = $this->department($this->set);
        $second = $this->department($this->set);
        $this->include($first, $second);
        $this->include($second, $first);

        $this->assertSame([$second->id], $this->descendantIds($first, '2024-04-01'));
        $this->assertSame([$second->id], $this->ancestorIds($first, '2024-04-01'));
    }

    /**
     * 頂点の下に n 段の組織を 1 列に作る(上から順に返す)。
     *
     * @param  array{0: string, 1: string|null, 2?: bool}  $term  内包の期間
     * @return list<Department>
     */
    private function chainUnderRoot(int $levels, array $term = ['2020-01-01', null]): array
    {
        $chain = [];
        $parent = $this->root;

        for ($level = 0; $level < $levels; $level++) {
            $child = $this->department($this->set, sortKey: $level + 1);
            $this->include($parent, $child, $term);
            $chain[] = $child;
            $parent = $child;
        }

        return $chain;
    }

    /**
     * 上位の組織の id(並びに依らず比べるため、id の昇順)。
     *
     * @return array<int, int>
     */
    private function ancestorIds(Department $department, string $date): array
    {
        return app(DepartmentTreeQuery::class)->ancestors($department->id, self::day($date))->map(fn (Department $ancestor) => $ancestor->id)->sort()->values()->all();
    }

    /**
     * 下位の組織の id(id の昇順)。
     *
     * @return array<int, int>
     */
    private function descendantIds(Department $department, string $date): array
    {
        return app(DepartmentTreeQuery::class)->descendants($department->id, self::day($date))->map(fn (Department $descendant) => $descendant->id)->sort()->values()->all();
    }
}

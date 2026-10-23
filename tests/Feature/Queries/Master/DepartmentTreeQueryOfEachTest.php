<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentSet;
use App\Queries\Master\DepartmentTreeQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 起点を複数渡す上位・下位の組織(docs の 設計/共通マスタ/組織とユーザー.md 5 節・9 節): 起点ごとの結果と並びが起点 1 つの口と同じで、
 * 自分を含まない。起点 1 つの口の並び(ソートキー・コード・id)と、返す組織の属性も確かめる。
 *
 * 組織の木 = 頂点 R > A > A1、R > B(A と B は兄弟)。ソートキー = R 0・B 1・A 2・A1 3。
 */
final class DepartmentTreeQueryOfEachTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private DepartmentSet $set;

    /** @var array<string, Department> */
    private array $departments = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create();
        $this->departments['R'] = $this->department($this->set, isRoot: true);

        foreach (['B' => ['R', 1], 'A' => ['R', 2], 'A1' => ['A', 3]] as $name => [$parent, $sortKey]) {
            $this->departments[$name] = $this->department($this->set, sortKey: $sortKey);
            $this->include($this->departments[$parent], $this->departments[$name]);
        }
    }

    public function test_reads_the_upper_and_lower_departments_of_each_origin_in_the_order_of_one_origin(): void
    {
        $origins = $this->idsOf(['A1', 'A', 'B', 'R']);
        $tree = app(DepartmentTreeQuery::class);
        $ancestors = $tree->ancestorsOfEach($origins, self::day(self::DATE));
        $descendants = $tree->descendantsOfEach($origins, self::day(self::DATE));

        $this->assertSame($origins, array_keys($ancestors));
        $this->assertSame($origins, array_keys($descendants));
        $this->assertSame($this->idsOf(['R', 'A']), $ancestors[$this->departments['A1']->id]->modelKeys());
        $this->assertSame($this->idsOf(['B', 'A', 'A1']), $descendants[$this->departments['R']->id]->modelKeys());
        $this->assertSame($this->idsOf(['R']), $ancestors[$this->departments['B']->id]->modelKeys());
        $this->assertSame([], $descendants[$this->departments['B']->id]->modelKeys());

        foreach ($origins as $origin) {
            $this->assertSameDepartments($tree->ancestors($origin, self::day(self::DATE)), $ancestors[$origin]);
            $this->assertSameDepartments($tree->descendants($origin, self::day(self::DATE)), $descendants[$origin]);
            $this->assertNotContains($origin, $ancestors[$origin]->modelKeys());
            $this->assertNotContains($origin, $descendants[$origin]->modelKeys());
        }
    }

    public function test_the_departments_of_one_origin_keep_only_the_columns_of_the_department(): void
    {
        $ancestors = app(DepartmentTreeQuery::class)->ancestors($this->departments['A1']->id, self::day(self::DATE));
        $ancestor = $ancestors->first();

        $this->assertNotNull($ancestor);
        $this->assertSame(
            array_keys(Department::query()->findOrFail($this->departments['R']->id)->getAttributes()),
            array_keys($ancestor->getAttributes()),
        );
    }

    public function test_a_cycle_reaches_the_other_department_for_each_origin_but_not_the_origin_itself(): void
    {
        $first = $this->department($this->set);
        $second = $this->department($this->set);
        $this->include($first, $second);
        $this->include($second, $first);
        $tree = app(DepartmentTreeQuery::class);

        $ancestors = $tree->ancestorsOfEach([$first->id, $second->id], self::day(self::DATE));
        $descendants = $tree->descendantsOfEach([$first->id, $second->id], self::day(self::DATE));

        $this->assertSame([$second->id], $ancestors[$first->id]->modelKeys());
        $this->assertSame([$first->id], $ancestors[$second->id]->modelKeys());
        $this->assertSame([$second->id], $descendants[$first->id]->modelKeys());
        $this->assertSame([$first->id], $descendants[$second->id]->modelKeys());
    }

    public function test_no_origin_reads_nothing_without_a_query(): void
    {
        $tree = app(DepartmentTreeQuery::class);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $ancestors = $tree->ancestorsOfEach([], self::day(self::DATE));
        $descendants = $tree->descendantsOfEach([], self::day(self::DATE));
        DB::disableQueryLog();

        $this->assertSame([], $ancestors);
        $this->assertSame([], $descendants);
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_the_descendants_over_effective_rows_pass_a_department_not_effective_in_order(): void
    {
        $middle = $this->department($this->set, [['2020-01-01', '2024-04-01']], sortKey: 5);
        $leaf = $this->department($this->set, sortKey: 4);
        $this->include($this->departments['B'], $middle);
        $this->include($middle, $leaf);

        $tree = app(DepartmentTreeQuery::class);
        $b = $this->departments['B']->id;

        $this->assertSame([$leaf->id, $middle->id], $tree->descendantIdsOverEffectiveRows($b, self::day(self::DATE)));
        $this->assertSame([], $tree->descendants($b, self::day(self::DATE))->modelKeys());
        DepartmentInclusion::query()->where('department_id', $leaf->id)->update(['is_deleted' => true]);
        $this->assertSame([$middle->id], $tree->descendantIdsOverEffectiveRows($b, self::day(self::DATE)));
    }

    /**
     * @param  Collection<int, Department>  $expected
     * @param  Collection<int, Department>  $actual
     */
    private function assertSameDepartments(Collection $expected, Collection $actual): void
    {
        $this->assertSame(
            $expected->map(fn (Department $department) => $department->getAttributes())->all(),
            $actual->map(fn (Department $department) => $department->getAttributes())->all(),
        );
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function idsOf(array $names): array
    {
        return array_map(fn (string $name) => $this->departments[$name]->id, $names);
    }
}

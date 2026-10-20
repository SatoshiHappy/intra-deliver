<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Queries\Master\Api\DepartmentTree;
use App\Queries\Master\DepartmentTreeQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 組織の上位の組織の公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節: 近い順の id(根まで。自分を含まない)。d に有効でない組織・
 * d に親の内包が無い組織は空)。上位の集まりは共通マスタの 9 節の引き方({@see DepartmentTreeQuery::ancestors()})と同じ。初期データ(2025-04-01 に
 * 品質保証部が開発本部 → 管理本部へ、第二営業部の新設)で確かめる。
 */
final class DepartmentTreeTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_returns_the_upper_departments_nearest_first_up_to_the_root(): void
    {
        $this->assertSame(['sales-1', 'sales', 'deliver'], $this->ancestorCodes('tokyo-sales', '2026-01-01'));
        $this->assertSame(['engineering', 'deliver'], $this->ancestorCodes('quality-assurance', '2025-03-31'));
        $this->assertSame(['administration', 'deliver'], $this->ancestorCodes('quality-assurance', '2025-04-01'));
        $this->assertSame([], $this->ancestorCodes('deliver', '2026-01-01'));
    }

    public function test_holds_the_same_departments_as_the_reading_of_the_common_master(): void
    {
        foreach (['2025-03-31', '2026-01-01'] as $date) {
            foreach (Department::query()->pluck('id')->map(fn (mixed $id) => (int) $id) as $departmentId) {
                $expected = app(DepartmentTreeQuery::class)->ancestors($departmentId, self::day($date))->modelKeys();
                $actual = $this->tree()->ancestorIdsOn($departmentId, self::day($date));
                sort($expected);
                sort($actual);

                $this->assertSame($expected, $actual, "{$departmentId} {$date}");
            }
        }
    }

    public function test_is_empty_for_a_department_not_effective_on_the_date_or_without_a_parent_or_missing(): void
    {
        $set = DepartmentSet::factory()->create();
        $root = $this->department($set, isRoot: true);
        $ended = $this->department($set, [['2020-01-01', '2026-01-01']]);
        $this->include($root, $ended);
        $isolated = $this->department($set);

        $this->assertSame([], $this->ancestorCodes('sales-2', '2025-03-31'));
        $this->assertSame([$root->id], $this->tree()->ancestorIdsOn($ended->id, self::day('2025-12-31')));
        $this->assertSame([], $this->tree()->ancestorIdsOn($ended->id, self::day('2026-01-01')));
        $this->assertSame([], $this->tree()->ancestorIdsOn($isolated->id, self::day('2026-01-01')));
        $this->assertSame([], $this->tree()->ancestorIdsOn((int) Department::query()->max('id') + 1, self::day('2026-01-01')));
    }

    public function test_stops_at_a_cycle_that_the_business_rules_did_not_prevent(): void
    {
        $set = DepartmentSet::factory()->create();
        $a = $this->department($set);
        $b = $this->department($set);
        $c = $this->department($set);
        $this->include($b, $a);
        $this->include($c, $b);
        $this->include($a, $c);

        $this->assertSame([$b->id, $c->id], $this->tree()->ancestorIdsOn($a->id, self::day('2026-01-01')));
    }

    private function tree(): DepartmentTree
    {
        return app(DepartmentTree::class);
    }

    /**
     * @return list<string>
     */
    private function ancestorCodes(string $code, string $date): array
    {
        $codes = Department::query()->pluck('code', 'id')->all();

        return array_map(fn (int $id) => $codes[$id], $this->tree()->ancestorIdsOn(Department::query()->where('code', $code)->sole()->id, self::day($date)));
    }
}

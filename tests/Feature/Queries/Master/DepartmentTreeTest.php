<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Queries\Master\Api\DepartmentTree;
use App\Queries\Master\Api\MasterCandidates;
use App\Queries\Master\DepartmentTreeQuery;
use App\Queries\Master\MasterSearchQuery;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 組織の上位の組織の公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節: 近い順の id(根まで。自分を含まない)。d に有効でない組織・
 * d に親の内包が無い組織は空)。上位の集まりは共通マスタの 9 節の引き方({@see DepartmentTreeQuery::ancestors()})と同じ。初期データ(2025-04-01 に
 * 品質保証部が開発本部 → 管理本部へ、第二営業部の新設)で確かめる。
 * 組織図の木にある組織の確かめ(同じ 3 節の idsInTreeOn の行: 渡した id のうち d の木にあるものを昇順・重ねずに。無い id は入れない)は、
 * 共通マスタの検索の組織の絞り({@see MasterSearchQuery}。候補の口 {@see MasterCandidates} の組織も)と同じ集まりになる。
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

    public function test_ids_in_the_tree_are_ascending_without_duplicates_missing_or_outside_departments(): void
    {
        $set = $this->defaultSet();
        $outside = $this->department($set);
        $otherSet = $this->department(DepartmentSet::factory()->create(), isRoot: true);
        $sales = $this->idOf('sales');
        $deliver = $this->idOf('deliver');
        $missing = (int) Department::query()->max('id') + 1;

        $given = [$sales, $outside->id, $missing, $deliver, $sales, $otherSet->id];

        $ids = $this->tree()->idsInTreeOn($given, self::day('2026-01-01'));

        $this->assertSame([min($sales, $deliver), max($sales, $deliver)], $ids);
        $this->assertSame([], $this->tree()->idsInTreeOn([$outside->id, $missing], self::day('2026-01-01')));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notIntegers(): array
    {
        return ['数の文字列' => ['1'], '小数' => [1.0], 'null' => [null], '真偽' => [true]];
    }

    #[DataProvider('notIntegers')]
    public function test_a_value_not_an_integer_is_a_logic_error(mixed $value): void
    {
        $this->expectException(LogicException::class);

        $inTree = fn (mixed $ids) => $this->tree()->idsInTreeOn($ids, self::day('2026-01-01'));

        $inTree([$this->idOf('sales'), $value]);
    }

    public function test_keys_that_are_not_a_list_are_read_by_the_values(): void
    {
        $sales = $this->idOf('sales');
        $inTree = fn (mixed $ids) => $this->tree()->idsInTreeOn($ids, self::day('2026-01-01'));

        $this->assertSame([$sales], $inTree([5 => $sales]));
    }

    public function test_a_department_leaves_the_tree_on_the_end_day_of_its_term(): void
    {
        $ended = $this->department($this->defaultSet(), [['2020-01-01', '2026-01-01']]);
        $this->include(Department::query()->where('code', 'deliver')->sole(), $ended);

        $this->assertSame([$ended->id], $this->tree()->idsInTreeOn([$ended->id], self::day('2025-12-31')));
        $this->assertSame([], $this->tree()->idsInTreeOn([$ended->id], self::day('2026-01-01')));
    }

    public function test_the_departments_in_the_tree_are_the_departments_of_the_search(): void
    {
        $this->department($this->defaultSet());

        foreach (['2025-03-31', '2026-01-01'] as $date) {
            $searched = array_column(
                app(MasterSearchQuery::class)->departments('', self::day($date), 'ja', PHP_INT_MAX),
                'id',
            );
            sort($searched);
            $all = array_values(array_map('intval', Department::query()->orderByDesc('id')->pluck('id')->all()));
            $some = array_slice($all, 0, 5);

            $this->assertSame($searched, $this->tree()->idsInTreeOn($all, self::day($date)), $date);
            $candidates = app(MasterCandidates::class)->departmentsOn('部', self::day($date), 'ja', 100);
            $candidates = array_column($candidates, 'id');
            $this->assertNotSame([], $candidates);
            $this->assertSame([], array_diff($candidates, $searched), $date);
            $expected = array_values(array_intersect($searched, $some));
            $this->assertSame($expected, $this->tree()->idsInTreeOn($some, self::day($date)), $date);
        }
    }

    public function test_an_empty_input_is_empty_without_queries_and_a_reading_takes_no_lock(): void
    {
        $sales = $this->idOf('sales');
        $empty = $this->statementsOf(
            fn () => $this->assertSame([], $this->tree()->idsInTreeOn([], self::day('2026-01-01'))),
        );
        $statements = $this->statementsOf(fn () => $this->tree()->idsInTreeOn([$sales], self::day('2026-01-01')));

        $this->assertSame([], $empty);
        $this->assertNotSame([], $statements);

        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|with)\b/', $sql);
            $this->assertStringNotContainsString('for update', $sql);
            $this->assertStringNotContainsString('for share', $sql);
        }
    }

    /**
     * @return list<string> 呼んだ後までに流れた SQL(小文字)
     */
    private function statementsOf(Closure $action): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });
        $action();

        return $statements;
    }

    private function defaultSet(): DepartmentSet
    {
        return DepartmentSet::query()->where('is_default', true)->firstOrFail();
    }

    private function idOf(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
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

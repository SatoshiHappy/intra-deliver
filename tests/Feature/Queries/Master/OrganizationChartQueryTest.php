<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\Company;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Queries\Master\OrganizationChartQuery;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 組織図の読み取り(docs の 設計/共通マスタ/検索と組織図.md 1.2・1.3): 表示する組織セット(既定の組織セットのうち、会社の並びで最初の会社のもの)の
 * d の組織ツリー(兄弟は子の側の内包の sort_key・id の順。頂点が d に有効でなければ null)と、選んだ組織(木に無ければ頂点)と所属ユーザー。
 * 組み立てた組織セットで確かめる(初期データでの形は OrganizationChartControllerTest)。
 */
final class OrganizationChartQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private DepartmentSet $set;

    /**
     * 組織図は既定の組織セットを 1 つ選ぶので、初期データの組織セットを既定から外してから(TX の中。巻き戻しで戻る)、テストの既定の組織セットを作る。
     * 初期データの組織セットは所属などの外部キーがあり消せないので、既定の印だけを外す。
     */
    protected function setUp(): void
    {
        parent::setUp();
        DepartmentSet::query()->update(['is_default' => false]);
        $this->set = DepartmentSet::factory()->create(['is_default' => true]);
    }

    public function test_the_tree_is_null_when_the_root_is_not_effective_on_the_date(): void
    {
        $root = $this->department($this->set, [['2024-04-02', null]], isRoot: true);
        $this->include($root, $this->department($this->set));

        $this->assertNull($this->chart()->treeOn(self::day(self::DATE), 'ja'));
        $this->assertSame(['selectedDepartment' => null, 'members' => []], $this->chart()->selectionOn($root->id, self::day(self::DATE), 'ja'));
    }

    public function test_the_tree_is_null_without_a_default_department_set(): void
    {
        $this->set->update(['is_default' => false]);
        $this->department($this->set, isRoot: true);

        $this->assertNull($this->chart()->treeOn(self::day(self::DATE), 'ja'));
    }

    public function test_siblings_are_in_the_order_of_their_inclusions_and_names_fall_back_to_null(): void
    {
        $root = $this->department($this->set, isRoot: true);
        $first = $this->department($this->set, sortKey: 9);
        $second = $this->department($this->set, sortKey: 1);
        $third = $this->department($this->set, sortKey: 1);
        $grandchild = $this->department($this->set);
        $this->include($root, $second)->update(['sort_key' => 2]);
        $this->include($root, $first)->update(['sort_key' => 1]);
        $this->include($root, $third)->update(['sort_key' => 2]);
        $this->include($first, $grandchild);
        $this->nameDepartment($root, 'デリバー商事');

        $tree = $this->chart()->treeOn(self::day(self::DATE), 'ja');

        $this->assertSame([
            'id' => $root->id, 'code' => $root->code, 'name' => 'デリバー商事', 'children' => [
                ['id' => $first->id, 'code' => $first->code, 'name' => null, 'children' => [
                    ['id' => $grandchild->id, 'code' => $grandchild->code, 'name' => null, 'children' => []],
                ]],
                ['id' => $second->id, 'code' => $second->code, 'name' => null, 'children' => []],
                ['id' => $third->id, 'code' => $third->code, 'name' => null, 'children' => []],
            ],
        ], $tree);
    }

    public function test_the_tree_is_the_default_set_of_the_first_company(): void
    {
        $this->set->company?->update(['sort_key' => 2]);
        $this->department($this->set, isRoot: true);
        $firstSet = DepartmentSet::factory()->create(['is_default' => true, 'company_id' => Company::factory()->create(['sort_key' => 1])->id]);
        $firstRoot = $this->department($firstSet, isRoot: true);
        $this->department(DepartmentSet::factory()->create(['company_id' => $firstSet->company_id]), isRoot: true);

        $this->assertSame($firstRoot->id, $this->chart()->treeOn(self::day(self::DATE), 'ja')['id'] ?? null);
    }

    public function test_a_department_out_of_the_tree_selects_the_root(): void
    {
        $root = $this->department($this->set, isRoot: true);
        $child = $this->department($this->set);
        $this->include($root, $child);
        $unreachable = $this->department($this->set);

        $this->assertSame($child->id, $this->selectedId($child->id));
        $this->assertSame($root->id, $this->selectedId($unreachable->id));
        $this->assertSame($root->id, $this->selectedId(null));
        $this->assertSame($root->id, $this->selectedId(0));
    }

    public function test_members_are_the_memberships_in_order_with_their_posts_in_order(): void
    {
        $root = $this->department($this->set, isRoot: true);
        $concurrent = $this->user();
        $main = $this->user();
        $this->join($concurrent, $this->department($this->set), isMain: true);
        $concurrentMembership = $this->join($concurrent, $root, sortKey: 2);
        $this->join($main, $root, isMain: true, sortKey: 1);
        $later = $this->companyPost($this->set, 30);
        $earlier = $this->companyPost($this->set, 40);
        $later->update(['sort_key' => 2]);
        $earlier->update(['sort_key' => 1]);
        $this->assignPost($concurrentMembership, $later);
        $this->assignPost($concurrentMembership, $earlier);

        $members = $this->chart()->selectionOn(null, self::day(self::DATE), 'ja')['members'];

        $this->assertSame([
            ['id' => $main->id, 'code' => $main->code, 'name' => null, 'isMain' => true, 'posts' => []],
            ['id' => $concurrent->id, 'code' => $concurrent->code, 'name' => null, 'isMain' => false, 'posts' => [
                ['id' => $earlier->id, 'code' => $earlier->code, 'name' => null],
                ['id' => $later->id, 'code' => $later->code, 'name' => null],
            ]],
        ], $members);
    }

    public function test_posts_with_all_digit_codes_are_in_the_order_of_the_database(): void
    {
        $root = $this->department($this->set, isRoot: true);
        $membership = $this->join($this->user(), $root, isMain: true);
        $two = $this->companyPost($this->set, 10);
        $ten = $this->companyPost($this->set, 20);
        $two->update(['code' => '2', 'sort_key' => 1]);
        $ten->update(['code' => '10', 'sort_key' => 1]);
        $this->assignPost($membership, $two);
        $this->assignPost($membership, $ten);

        $members = $this->chart()->selectionOn(null, self::day(self::DATE), 'ja')['members'];

        $this->assertSame(['10', '2'], array_column($members[0]['posts'], 'code'), '同じ sort_key の数字だけのコードは DB の文字列の順(10 → 2)');
    }

    public function test_reads_the_tree_once_for_the_tree_and_the_selection(): void
    {
        $root = $this->department($this->set, isRoot: true);
        $this->include($root, $this->department($this->set));
        $chart = $this->chart();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $chart->treeOn(self::day(self::DATE), 'ja');
        $chart->selectionOn($root->id, self::day(self::DATE), 'ja');
        $treeQueries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'WITH RECURSIVE'));
        DB::disableQueryLog();

        $this->assertCount(1, $treeQueries);
    }

    private function chart(): OrganizationChartQuery
    {
        return app(OrganizationChartQuery::class);
    }

    private function selectedId(?int $departmentId): ?int
    {
        return $this->chart()->selectionOn($departmentId, self::day(self::DATE), 'ja')['selectedDepartment']['id'] ?? null;
    }

    private function nameDepartment(Department $department, string $name): void
    {
        DepartmentTermTranslation::factory()->create([
            'department_term_id' => $department->departmentTerms()->sole()->id,
            'locale_id' => Locale::query()->where('code', 'ja')->sole()->id,
            'name' => $name,
        ]);
    }
}

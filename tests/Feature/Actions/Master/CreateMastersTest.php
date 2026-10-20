<?php

namespace Tests\Feature\Actions\Master;

use App\Actions\Master\CreateCompany;
use App\Actions\Master\CreateCompanyPost;
use App\Actions\Master\CreateDepartment;
use App\Actions\Master\CreateUser;
use App\Enums\Master\Sex;
use App\Models\Master\Company;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTermTranslation;
use App\Queries\Master\TermQuery;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * マスタを作る業務処理(docs の 設計/共通マスタ/組織とユーザー.md 1〜4 節・6 節・7 節。
 * 期間化するマスタの最初の期間の行は [開始日, 上限なし) = 共通の規則 5.3。会社は既定の組織セットと頂点の組織を同時に作る)。
 */
final class CreateMastersTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use BuildsCommonMaster;

    public function test_a_company_comes_with_its_default_department_set_and_its_root_department(): void
    {
        $company = app(CreateCompany::class)->execute('example', 'default', 'example', self::day('2024-04-01'), [
            'ja' => ['name' => 'エグザンプル商事'],
            'en' => ['name' => 'Example Trading'],
        ]);

        $sets = $company->departmentSets()->get();
        $this->assertSame([true], $sets->pluck('is_default')->all());
        $root = Department::query()->where('department_set_id', $sets->sole()->id)->sole();
        $this->assertTrue($root->is_root);
        $this->assertSame('エグザンプル商事', app(TermQuery::class)->departmentTermOn($root->id, self::day('2024-04-01'), 'ja')?->departmentTermTranslations->sole()->name);
        $this->assertNull(app(TermQuery::class)->departmentTermOn($root->id, self::day('2024-03-31'), 'ja'));
    }

    public function test_a_company_is_not_left_half_made_when_a_locale_is_unknown(): void
    {
        $counts = [Company::query()->count(), DepartmentSet::query()->count()];

        $this->assertRejected('locale', fn () => app(CreateCompany::class)->execute('example', 'default', 'example', self::day('2024-04-01'), ['fr' => ['name' => 'Example']]));

        $this->assertSame($counts, [Company::query()->count(), DepartmentSet::query()->count()]);
    }

    public function test_a_department_starts_with_one_term_without_an_end_and_copies_its_name_to_the_short_name(): void
    {
        $set = DepartmentSet::factory()->create();

        $department = app(CreateDepartment::class)->execute($set->id, 'sales', self::day('2024-04-01'), [
            'ja' => ['name' => '営業部'],
            'en' => ['name' => 'Sales Department', 'short_name' => 'Sales'],
        ], sortKey: 3);

        $term = $department->departmentTerms()->sole();
        $this->assertSame(['2024-04-01', null, false], [$term->start_date->toDateString(), $term->end_date, $term->is_deleted]);
        $this->assertFalse($department->is_root);
        $this->assertSame(3, $department->sort_key);
        $this->assertEqualsCanonicalizing(['営業部', 'Sales'], $term->departmentTermTranslations->map(fn (DepartmentTermTranslation $translation) => $translation->short_name)->all());
    }

    public function test_a_null_short_name_copies_the_name_like_an_omitted_one(): void
    {
        $set = DepartmentSet::factory()->create();

        $department = app(CreateDepartment::class)->execute($set->id, 'sales', self::day('2024-04-01'), ['ja' => ['name' => '営業部', 'short_name' => null]]);

        $this->assertSame(['営業部'], $department->departmentTerms()->sole()->departmentTermTranslations->map(fn (DepartmentTermTranslation $translation) => $translation->short_name)->all());
    }

    public function test_rejects_an_unknown_locale(): void
    {
        $set = DepartmentSet::factory()->create();
        $departments = Department::query()->count();

        $this->assertRejected('locale', fn () => app(CreateDepartment::class)->execute($set->id, 'sales', self::day('2024-04-01'), ['xx' => ['name' => '営業部']]));
        $this->assertSame($departments, Department::query()->count());
    }

    public function test_a_company_post_keeps_its_rank_on_the_anchor_and_starts_with_one_term(): void
    {
        $set = DepartmentSet::factory()->create();

        $companyPost = app(CreateCompanyPost::class)->execute($set->id, 'manager', 30, self::day('2024-04-01'), ['ja' => ['name' => '部長'], 'en' => ['name' => 'Manager']]);

        $this->assertSame(30, $companyPost->rank);
        $term = app(TermQuery::class)->companyPostTermOn($companyPost->id, self::day('2030-01-01'), 'en');
        $this->assertNotNull($term);
        $this->assertSame('Manager', $term->companyPostTermTranslations->sole()->name);
        $this->assertNull($term->end_date);
    }

    public function test_a_user_starts_with_one_term_holding_the_sex(): void
    {
        $user = app(CreateUser::class)->execute('u001', self::day('2024-04-01'), ['ja' => ['name' => '山田 太郎'], 'en' => ['name' => 'Taro Yamada']], Sex::NotAnswered);

        $term = app(TermQuery::class)->userTermOn($user->id, self::day('2024-04-01'), 'ja');
        $this->assertNotNull($term);
        $this->assertSame(Sex::NotAnswered, $term->sex);
        $this->assertSame('山田 太郎', $term->userTermTranslations->sole()->name);
        $this->assertNull(app(TermQuery::class)->userTermOn($user->id, self::day('2024-03-31'), 'ja'));
    }
}

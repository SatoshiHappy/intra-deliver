<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\CompanyPostTermTranslation;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\UserTermTranslation;
use App\Queries\Master\TermQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 期間化するマスタの d 時点の値(docs の 設計/共通マスタ/共通の規則.md 5.4: アンカーと、d に有効な期間の行と、そのロケール別の行。
 * d に有効な期間の行が無ければ、その事柄は d には無い)。
 */
final class TermQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private Locale $ja;

    private Locale $en;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ja = Locale::query()->where('code', 'ja')->sole();
        $this->en = Locale::query()->where('code', 'en')->sole();
    }

    public function test_picks_the_term_effective_on_the_date_with_the_name_in_the_locale(): void
    {
        $department = $this->department(DepartmentSet::factory()->create(), [['2020-01-01', '2024-04-01'], ['2024-04-01', null]]);
        [$before, $after] = $department->departmentTerms()->orderBy('start_date')->get()->all();
        $this->nameTerm($before, $this->ja, '営業部');
        $this->nameTerm($after, $this->ja, '第一営業部');
        $this->nameTerm($after, $this->en, 'Sales Dept. 1');

        $term = app(TermQuery::class)->departmentTermOn($department->id, self::day('2024-04-01'), 'ja');

        $this->assertNotNull($term);
        $this->assertSame($after->id, $term->id);
        $this->assertSame($department->id, $term->department?->id);
        $this->assertSame(['第一営業部'], $term->departmentTermTranslations->pluck('name')->all());
        $this->assertSame('営業部', app(TermQuery::class)->departmentTermOn($department->id, self::day('2024-03-31'), 'ja')?->departmentTermTranslations->first()?->name);
    }

    public function test_returns_nothing_before_the_first_term(): void
    {
        $department = $this->department(DepartmentSet::factory()->create(), [['2024-04-01', null]]);

        $this->assertNull(app(TermQuery::class)->departmentTermOn($department->id, self::day('2024-03-31'), 'ja'));
    }

    public function test_returns_nothing_on_a_deleted_term(): void
    {
        $department = $this->department(DepartmentSet::factory()->create(), [['2020-01-01', '2024-04-01'], ['2024-04-01', '2025-04-01', true], ['2025-04-01', null]]);

        $this->assertNull(app(TermQuery::class)->departmentTermOn($department->id, self::day('2024-04-01'), 'ja'));
        $this->assertNotNull(app(TermQuery::class)->departmentTermOn($department->id, self::day('2025-04-01'), 'ja'));
    }

    public function test_returns_the_term_without_translations_when_the_locale_has_no_row(): void
    {
        $department = $this->department(DepartmentSet::factory()->create());
        $this->nameTerm($department->departmentTerms()->sole(), $this->ja, '総務部');

        $term = app(TermQuery::class)->departmentTermOn($department->id, self::day('2024-04-01'), 'en');

        $this->assertNotNull($term);
        $this->assertTrue($term->departmentTermTranslations->isEmpty());
    }

    public function test_reads_the_company_post_on_the_date(): void
    {
        $companyPost = $this->companyPost(DepartmentSet::factory()->create(), 30, [['2020-01-01', '2024-04-01']]);
        CompanyPostTermTranslation::factory()->create(['company_post_term_id' => $companyPost->companyPostTerms()->sole()->id, 'locale_id' => $this->en->id, 'name' => 'Manager']);

        $term = app(TermQuery::class)->companyPostTermOn($companyPost->id, self::day('2024-03-31'), 'en');

        $this->assertNotNull($term);
        $this->assertSame(30, $term->companyPost?->rank);
        $this->assertSame(['Manager'], $term->companyPostTermTranslations->pluck('name')->all());
        $this->assertNull(app(TermQuery::class)->companyPostTermOn($companyPost->id, self::day('2024-04-01'), 'en'));
    }

    public function test_reads_the_user_on_the_date(): void
    {
        $user = $this->user([['2020-01-01', null, true]]);
        UserTermTranslation::factory()->create(['user_term_id' => $user->userTerms()->sole()->id, 'locale_id' => $this->ja->id, 'name' => '山田']);

        $whileDeleted = app(TermQuery::class)->userTermOn($user->id, self::day('2024-04-01'), 'ja');
        $this->assertNull($whileDeleted);

        $user->userTerms()->update(['is_deleted' => false]);
        $term = app(TermQuery::class)->userTermOn($user->id, self::day('2024-04-01'), 'ja');

        $this->assertNotNull($term);
        $this->assertSame($user->id, $term->user?->id);
        $this->assertSame(['山田'], $term->userTermTranslations->pluck('name')->all());
    }

    public function test_reads_the_departments_on_the_date_keyed_by_their_ids(): void
    {
        $set = DepartmentSet::factory()->create();
        $named = $this->department($set, [['2020-01-01', '2024-04-01'], ['2024-04-01', null]]);
        $this->nameTerm($named->departmentTerms()->orderBy('start_date')->get()->all()[1], $this->ja, '第一営業部');
        $unnamed = $this->department($set);
        $notYet = $this->department($set, [['2024-04-02', null]]);

        $terms = app(TermQuery::class)->departmentTermsOn([$named->id, $unnamed->id, $notYet->id], self::day('2024-04-01'), 'ja');

        $this->assertEqualsCanonicalizing([$named->id, $unnamed->id], $terms->keys()->all());
        $this->assertSame(['第一営業部'], $terms->get($named->id)?->departmentTermTranslations->pluck('name')->all());
        $this->assertTrue($terms->get($unnamed->id)?->departmentTermTranslations->isEmpty());
    }

    public function test_reads_the_users_on_the_date_keyed_by_their_ids(): void
    {
        $named = $this->user();
        UserTermTranslation::factory()->create(['user_term_id' => $named->userTerms()->sole()->id, 'locale_id' => $this->ja->id, 'name' => '山田']);
        $unnamed = $this->user();
        $retired = $this->user([['2020-01-01', '2024-04-01']]);

        $terms = app(TermQuery::class)->userTermsOn([$named->id, $unnamed->id, $retired->id], self::day('2024-04-01'), 'ja');

        $this->assertEqualsCanonicalizing([$named->id, $unnamed->id], $terms->keys()->all());
        $this->assertSame(['山田'], $terms->get($named->id)?->userTermTranslations->pluck('name')->all());
        $this->assertTrue($terms->get($unnamed->id)?->userTermTranslations->isEmpty());
    }

    public function test_reads_the_company_posts_on_the_date_keyed_by_their_ids(): void
    {
        $set = DepartmentSet::factory()->create();
        $named = $this->companyPost($set, 30);
        CompanyPostTermTranslation::factory()->create(['company_post_term_id' => $named->companyPostTerms()->sole()->id, 'locale_id' => $this->ja->id, 'name' => '部長']);
        $unnamed = $this->companyPost($set, 40);
        $abolished = $this->companyPost($set, 50, [['2020-01-01', '2024-04-01']]);

        $terms = app(TermQuery::class)->companyPostTermsOn([$named->id, $unnamed->id, $abolished->id], self::day('2024-04-01'), 'ja');

        $this->assertEqualsCanonicalizing([$named->id, $unnamed->id], $terms->keys()->all());
        $this->assertSame(['部長'], $terms->get($named->id)?->companyPostTermTranslations->pluck('name')->all());
        $this->assertTrue($terms->get($unnamed->id)?->companyPostTermTranslations->isEmpty());
    }

    private function nameTerm(DepartmentTerm $term, Locale $locale, string $name): void
    {
        DepartmentTermTranslation::factory()->create(['department_term_id' => $term->id, 'locale_id' => $locale->id, 'name' => $name]);
    }
}

<?php

namespace Tests\Feature\Actions\Master;

use App\Actions\Master\SplitDepartmentTerm;
use App\Actions\Master\SplitUserTerm;
use App\Enums\Master\Sex;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\UserTermTranslation;
use App\Queries\Master\TermQuery;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 期間化するマスタの期間の分割(docs の 設計/共通マスタ/共通の規則.md 5.5 の 4 行目: ロケール別の行を新しい期間へ写す。
 * 分けた両方は元の行の is_deleted を引き継ぐ)。
 */
final class SplitTermTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use BuildsCommonMaster;

    private Locale $ja;

    private Locale $en;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ja = Locale::query()->where('code', 'ja')->sole();
        $this->en = Locale::query()->where('code', 'en')->sole();
    }

    public function test_splits_a_department_term_and_changes_the_name_only_from_the_date(): void
    {
        $department = $this->namedDepartment([['2024-04-01', null]], ['ja' => '営業部', 'en' => 'Sales Dept.']);

        app(SplitDepartmentTerm::class)->execute($department->id, self::day('2025-04-01'), ['ja' => ['name' => '第一営業部']]);

        $this->assertSame([['2024-04-01', '2025-04-01', false], ['2025-04-01', null, false]], $this->termsOf($department));
        $this->assertSame(['営業部', '営業部'], $this->namesOn($department, '2025-03-31', 'ja'));
        $this->assertSame(['第一営業部', '第一営業部'], $this->namesOn($department, '2025-04-01', 'ja'));
        $this->assertSame(['Sales Dept.', 'Sales Dept.'], $this->namesOn($department, '2025-04-01', 'en'));
    }

    public function test_a_split_of_a_bounded_term_keeps_the_end_and_adds_a_new_locale_row(): void
    {
        $department = $this->namedDepartment([['2020-01-01', '2024-04-01'], ['2024-04-01', '2026-04-01']], ['ja' => '総務部']);

        app(SplitDepartmentTerm::class)->execute($department->id, self::day('2025-04-01'), ['en' => ['name' => 'General Affairs', 'short_name' => 'GA']]);

        $this->assertSame([['2020-01-01', '2024-04-01', false], ['2024-04-01', '2025-04-01', false], ['2025-04-01', '2026-04-01', false]], $this->termsOf($department));
        $this->assertSame(['General Affairs', 'GA'], $this->namesOn($department, '2025-04-01', 'en'));
        $this->assertSame([], $this->namesOn($department, '2025-03-31', 'en'));
    }

    public function test_an_override_without_the_name_keeps_the_copied_short_name(): void
    {
        $department = $this->namedDepartment([['2024-04-01', null]], ['ja' => '総務部']);

        app(SplitDepartmentTerm::class)->execute($department->id, self::day('2025-04-01'), ['ja' => ['notes' => '移転']]);

        $this->assertSame(['総務部', '総務部'], $this->namesOn($department, '2025-04-01', 'ja'));
    }

    public function test_an_override_with_the_name_and_a_null_short_name_copies_the_name(): void
    {
        $department = $this->namedDepartment([['2024-04-01', null]], ['ja' => '総務部']);

        app(SplitDepartmentTerm::class)->execute($department->id, self::day('2025-04-01'), ['ja' => ['name' => '新総務部', 'short_name' => null]]);

        $this->assertSame(['新総務部', '新総務部'], $this->namesOn($department, '2025-04-01', 'ja'));
    }

    public function test_an_override_with_only_a_null_short_name_copies_the_name_of_the_term(): void
    {
        $department = $this->namedDepartment([['2024-04-01', null]], ['ja' => '総務部']);

        app(SplitDepartmentTerm::class)->execute($department->id, self::day('2025-04-01'), ['ja' => ['short_name' => null]]);

        $this->assertSame(['総務部', '総務部'], $this->namesOn($department, '2025-04-01', 'ja'));
    }

    public function test_both_halves_inherit_the_deleted_flag(): void
    {
        $department = $this->namedDepartment([['2020-01-01', '2024-04-01'], ['2024-04-01', null, true]], ['ja' => '旧部']);

        app(SplitDepartmentTerm::class)->execute($department->id, self::day('2025-04-01'));

        $this->assertSame([['2020-01-01', '2024-04-01', false], ['2024-04-01', '2025-04-01', true], ['2025-04-01', null, true]], $this->termsOf($department));
        $this->assertSame(1, DepartmentTermTranslation::query()->whereHas('departmentTerm', fn ($term) => $term->where('department_id', $department->id)->where('start_date', '2025-04-01'))->count());
    }

    public function test_rejects_a_date_outside_the_terms_or_on_the_start_of_a_term(): void
    {
        $department = $this->namedDepartment([['2024-04-01', null]], ['ja' => '営業部']);

        $this->assertRejected('date', fn () => app(SplitDepartmentTerm::class)->execute($department->id, self::day('2024-03-31')));
        $this->assertRejected('date', fn () => app(SplitDepartmentTerm::class)->execute($department->id, self::day('2024-04-01')));
        $this->assertSame([['2024-04-01', null, false]], $this->termsOf($department));
    }

    public function test_rejects_an_unknown_locale_without_splitting(): void
    {
        $department = $this->namedDepartment([['2024-04-01', null]], ['ja' => '営業部']);

        $this->assertRejected('locale', fn () => app(SplitDepartmentTerm::class)->execute($department->id, self::day('2025-04-01'), ['xx' => ['name' => '?']]));
        $this->assertSame([['2024-04-01', null, false]], $this->termsOf($department));
    }

    public function test_splits_a_user_term_keeping_the_sex_and_the_names(): void
    {
        $user = $this->user([['2024-04-01', null]]);
        $user->userTerms()->update(['sex' => Sex::Female]);
        UserTermTranslation::factory()->create(['user_term_id' => $user->userTerms()->sole()->id, 'locale_id' => $this->ja->id, 'name' => '佐藤 花子']);
        UserTermTranslation::factory()->create(['user_term_id' => $user->userTerms()->sole()->id, 'locale_id' => $this->en->id, 'name' => 'Hanako Sato']);

        $later = app(SplitUserTerm::class)->execute($user->id, self::day('2025-10-01'), ['ja' => ['name' => '鈴木 花子']]);

        $this->assertSame([Sex::Female, '2025-10-01'], [$later->sex, $later->start_date->toDateString()]);
        $this->assertSame('佐藤 花子', app(TermQuery::class)->userTermOn($user->id, self::day('2025-09-30'), 'ja')?->userTermTranslations->sole()->name);
        $this->assertSame('鈴木 花子', app(TermQuery::class)->userTermOn($user->id, self::day('2025-10-01'), 'ja')?->userTermTranslations->sole()->name);
        $this->assertSame('Hanako Sato', app(TermQuery::class)->userTermOn($user->id, self::day('2025-10-01'), 'en')?->userTermTranslations->sole()->name);
        $this->assertRejected('date', fn () => app(SplitUserTerm::class)->execute($user->id, self::day('2025-10-01')));
    }

    public function test_a_split_of_one_of_two_user_terms_copies_the_names_of_that_term(): void
    {
        $user = $this->user([['2020-01-01', '2024-04-01'], ['2024-04-01', null]]);

        foreach ($user->userTerms()->get() as $term) {
            UserTermTranslation::factory()->create(['user_term_id' => $term->id, 'locale_id' => $this->ja->id, 'name' => $term->start_date->toDateString() === '2020-01-01' ? '田中 花子' : '佐藤 花子']);
        }

        app(SplitUserTerm::class)->execute($user->id, self::day('2025-10-01'));

        $this->assertSame(3, $user->userTerms()->count());
        $this->assertSame('田中 花子', app(TermQuery::class)->userTermOn($user->id, self::day('2023-04-01'), 'ja')?->userTermTranslations->sole()->name);
        $this->assertSame('佐藤 花子', app(TermQuery::class)->userTermOn($user->id, self::day('2025-09-30'), 'ja')?->userTermTranslations->sole()->name);
        $this->assertSame('佐藤 花子', app(TermQuery::class)->userTermOn($user->id, self::day('2025-10-01'), 'ja')?->userTermTranslations->sole()->name);
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2?: bool}>  $terms
     * @param  array<string, string>  $names  すべての期間の行に付ける名称(ロケールのコード → 名称)
     */
    private function namedDepartment(array $terms, array $names): Department
    {
        $department = $this->department(DepartmentSet::factory()->create(), $terms);

        foreach ($department->departmentTerms()->get() as $term) {
            foreach ($names as $code => $name) {
                DepartmentTermTranslation::factory()->create(['department_term_id' => $term->id, 'locale_id' => $code === 'ja' ? $this->ja->id : $this->en->id, 'name' => $name, 'short_name' => $name]);
            }
        }

        return $department;
    }

    /**
     * @return list<array{string, string|null, bool}>
     */
    private function termsOf(Department $department): array
    {
        return array_values($department->departmentTerms()->orderBy('start_date')->get()
            ->map(fn (DepartmentTerm $term) => [$term->start_date->toDateString(), $term->end_date?->toDateString(), $term->is_deleted])->all());
    }

    /**
     * d に有効な期間の行の、ロケールの名称と略称(行が無ければ空)。
     *
     * @return list<string|null>
     */
    private function namesOn(Department $department, string $date, string $locale): array
    {
        $translation = app(TermQuery::class)->departmentTermOn($department->id, self::day($date), $locale)?->departmentTermTranslations->first();

        return $translation === null ? [] : [$translation->name, $translation->short_name];
    }
}

<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\CompanyPostTermTranslation;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Queries\Master\Api\MasterCandidates;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザー・組織・役職の候補の公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節: 共通マスタの検索と同じ合い方(コード・名前・
 * 検索用名称の部分一致、%・_・\ は文字そのもの、組織は組織図の木にあるものだけ)・並び(sort_key・code)を、基準日と件数で呼ぶ。空の語は空)。
 * 初期データ(兼務の u0009 = 総務部が主所属、2025-10-01 に退職の u0014、2026-11-01 に入社の u0015)で確かめる。
 */
final class MasterCandidatesTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_finds_users_by_the_name_with_the_name_of_the_main_department(): void
    {
        $this->assertSame(
            [['id' => User::query()->where('code', 'u0009')->sole()->id, 'code' => 'u0009', 'name' => '吉田 達也', 'mainDepartmentName' => '総務部']],
            $this->candidates()->usersOn('達也', self::day('2026-10-10'), 'ja', 20),
        );
        $this->assertSame(['u0009'], $this->codes($this->candidates()->usersOn('Yoshida', self::day('2026-10-10'), 'en', 20)));
        $this->assertSame([], $this->candidates()->usersOn('Yoshida', self::day('2026-10-10'), 'ja', 20));
    }

    public function test_finds_users_effective_on_the_date_in_the_order_of_the_sort_key_up_to_the_limit(): void
    {
        $this->assertSame(
            ['u0001', 'u0002', 'u0003', 'u0004', 'u0005', 'u0006', 'u0007', 'u0008', 'u0009', 'u0010', 'u0011', 'u0012', 'u0013', 'u0016', 'u0017'],
            $this->codes($this->candidates()->usersOn('U00', self::day('2026-10-10'), 'ja', 20)),
        );
        $this->assertSame(['u0001', 'u0002', 'u0003'], $this->codes($this->candidates()->usersOn('u00', self::day('2026-10-10'), 'ja', 3)));
        $this->assertSame(['u0014'], $this->codes($this->candidates()->usersOn('u0014', self::day('2025-09-30'), 'ja', 20)));
        $this->assertSame(['u0015'], $this->codes($this->candidates()->usersOn('u0015', self::day('2026-11-01'), 'ja', 20)));
        $this->assertSame([], $this->candidates()->usersOn('u0015', self::day('2026-10-10'), 'ja', 20));
    }

    public function test_finds_departments_in_the_tree_by_the_code_the_name_and_the_search_name(): void
    {
        $outside = $this->department(DepartmentSet::query()->where('is_default', true)->firstOrFail());
        DepartmentTermTranslation::factory()->create(['department_term_id' => $outside->departmentTerms()->sole()->id, 'locale_id' => $this->localeId('ja'), 'name' => '孤立部']);
        DepartmentTermTranslation::query()->where('name', '人事部')->update(['search_name' => 'じんじぶ']);

        $this->assertSame(
            ['administration', 'general-affairs', 'accounting', 'human-resources', 'sales', 'sales-1', 'sales-2', 'engineering', 'development', 'quality-assurance'],
            $this->codes($this->candidates()->departmentsOn('部', self::day('2026-10-10'), 'ja', 20)),
        );
        $this->assertSame(['administration', 'general-affairs'], $this->codes($this->candidates()->departmentsOn('部', self::day('2026-10-10'), 'ja', 2)));
        $this->assertSame(['sales-1'], $this->codes($this->candidates()->departmentsOn('SALES-1', self::day('2026-10-10'), 'ja', 20)));
        $this->assertSame(['human-resources'], $this->codes($this->candidates()->departmentsOn('じんじ', self::day('2026-10-10'), 'ja', 20)));
        $this->assertSame([], $this->candidates()->departmentsOn('孤立', self::day('2026-10-10'), 'ja', 20));
        $this->assertSame(['id', 'code', 'name'], array_keys($this->candidates()->departmentsOn('人事', self::day('2026-10-10'), 'ja', 20)[0]));
    }

    public function test_finds_company_posts_effective_on_the_date_by_the_code_and_the_name(): void
    {
        $ended = $this->companyPost(DepartmentSet::query()->where('is_default', true)->firstOrFail(), 70, [['2020-01-01', '2026-01-01']]);
        CompanyPostTermTranslation::factory()->create(['company_post_term_id' => $ended->companyPostTerms()->sole()->id, 'locale_id' => $this->localeId('ja'), 'name' => '室長']);

        $this->assertSame(
            ['president', 'division-head', 'manager', 'assistant-manager', 'section-chief', 'chief'],
            $this->codes($this->candidates()->companyPostsOn('長', self::day('2026-10-10'), 'ja', 20)),
        );
        $this->assertSame(['president', 'division-head'], $this->codes($this->candidates()->companyPostsOn('長', self::day('2026-10-10'), 'ja', 2)));
        $this->assertSame(['manager', 'assistant-manager'], $this->codes($this->candidates()->companyPostsOn('MANAGER', self::day('2026-10-10'), 'ja', 20)));
        $this->assertSame([['code' => 'section-chief', 'name' => 'Section Chief']], array_map(fn (array $post) => ['code' => $post['code'], 'name' => $post['name']], $this->candidates()->companyPostsOn('Section', self::day('2026-10-10'), 'en', 20)));
        $this->assertSame([$ended->code], $this->codes($this->candidates()->companyPostsOn('室長', self::day('2025-12-31'), 'ja', 20)));
        $this->assertSame([], $this->candidates()->companyPostsOn('室長', self::day('2026-01-01'), 'ja', 20));
    }

    public function test_lists_every_company_post_effective_on_the_date_in_the_order_of_the_candidates(): void
    {
        $set = DepartmentSet::query()->where('is_default', true)->firstOrFail();
        $ended = $this->companyPost($set, 70, [['2020-01-01', '2026-01-01']]);
        $seeded = ['president', 'division-head', 'manager', 'assistant-manager', 'section-chief', 'chief', 'senior'];
        $posts = $this->candidates()->effectiveCompanyPostsOn(self::day('2026-10-10'), 'ja');

        $this->assertSame($seeded, $this->codes($posts));
        $this->assertSame(['id', 'code', 'name'], array_keys($posts[0]));
        $this->assertSame('社長', $posts[0]['name']);
        $before = $this->candidates()->effectiveCompanyPostsOn(self::day('2025-12-31'), 'ja');
        $this->assertContains($ended->code, $this->codes($before));
    }

    #[TestWith(['%'])]
    #[TestWith(['_'])]
    #[TestWith(['\\'])]
    #[TestWith(['u00_9'])]
    #[TestWith([''])]
    public function test_wildcards_are_plain_characters_and_an_empty_keyword_finds_nothing(string $keyword): void
    {
        $date = self::day('2026-10-10');

        $this->assertSame(
            [[], [], []],
            [$this->candidates()->usersOn($keyword, $date, 'ja', 20), $this->candidates()->departmentsOn($keyword, $date, 'ja', 20), $this->candidates()->companyPostsOn($keyword, $date, 'ja', 20)],
        );
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_a_limit_below_one_is_a_logic_error(int $limit): void
    {
        $date = self::day('2026-10-10');

        foreach ([
            fn () => $this->candidates()->usersOn('u00', $date, 'ja', $limit),
            fn () => $this->candidates()->departmentsOn('部', $date, 'ja', $limit),
            fn () => $this->candidates()->companyPostsOn('長', $date, 'ja', $limit),
        ] as $call) {
            try {
                $call();
                $this->fail("件数 {$limit} が通った");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function candidates(): MasterCandidates
    {
        return app(MasterCandidates::class);
    }

    /**
     * @param  list<array{code: string}>  $rows
     * @return list<string>
     */
    private function codes(array $rows): array
    {
        return array_map(fn (array $row) => $row['code'], $rows);
    }

    private function localeId(string $code): int
    {
        return Locale::query()->where('code', $code)->sole()->id;
    }
}

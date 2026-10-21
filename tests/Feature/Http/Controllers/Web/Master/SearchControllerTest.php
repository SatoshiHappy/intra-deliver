<?php

namespace Tests\Feature\Http\Controllers\Web\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTermTranslation;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Master\UserTermTranslation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 共通マスタの検索の口(docs の 設計/共通マスタ/検索と組織図.md 2 節): GET /master/search?q= が、語を含む d に有効なユーザーと木の組織を
 * 各 10 件まで JSON で返す(氏名・名称・検索用名称はロケールの行だけ、コードも見る。大文字小文字を区別せず、%・_・\ は文字そのもの)。
 * q は任意(無い・空・空白だけは語で絞らず、先頭から各 10 件 = 参照の選択欄が開いた直後)。101 文字・配列・UTF-8 として正しくない語は 422。
 * 初期データ(兼務の u0009 = 総務部が主所属。退職者 u0014、入社前の u0015)を入れ、今日を 2026-10-10 に固定し、
 * ルートの認可を外さずにゲストで開く。
 */
final class SearchControllerTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
    }

    public function test_a_guest_finds_a_user_by_a_part_of_the_name_with_the_main_department(): void
    {
        $user = User::query()->where('code', 'u0009')->sole();

        $this->search('達也')->assertOk()->assertExactJson([
            'users' => [[
                'id' => $user->id,
                'code' => 'u0009',
                'name' => '吉田 達也',
                'mainDepartment' => ['id' => $this->departmentId('general-affairs'), 'code' => 'general-affairs', 'name' => '総務部'],
            ]],
            'departments' => [],
        ]);
    }

    public function test_the_response_is_not_stored_by_caches(): void
    {
        $response = $this->search('達也');

        $this->assertTrue($response->headers->getCacheControlDirective('private'));
        $this->assertTrue($response->headers->getCacheControlDirective('no-store'));
    }

    #[TestWith(['u0009'])]
    #[TestWith(['U0009'])]
    public function test_finds_a_user_by_the_code_ignoring_the_case(string $keyword): void
    {
        $this->search($keyword)->assertJsonPath('users.0.code', 'u0009')->assertJsonCount(1, 'users');
    }

    public function test_finds_a_user_and_a_department_by_the_search_name_of_the_locale_only(): void
    {
        $this->translationOfUser('u0001', 'ja')->update(['search_name' => 'ささき ようた']);
        $this->translationOfDepartment('human-resources')->update(['search_name' => 'じんじぶ']);

        $this->search('ようた')->assertJsonPath('users.0.code', 'u0001')->assertJsonCount(1, 'users');
        $this->search('じんじ')->assertJsonPath('departments', [['id' => $this->departmentId('human-resources'), 'code' => 'human-resources', 'name' => '人事部']]);
        $this->search('Yoshida')->assertExactJson(['users' => [], 'departments' => []]);
    }

    public function test_finds_departments_by_the_code_and_the_name(): void
    {
        $this->search('HUMAN')->assertJsonPath('departments.0.code', 'human-resources')->assertJsonCount(1, 'departments');
        $this->search('人事')->assertJsonPath('departments.0.code', 'human-resources')->assertJsonCount(1, 'departments');
    }

    public function test_leaves_out_users_not_effective_today(): void
    {
        $this->search('木村')->assertJsonCount(0, 'users');
        $this->search('u0014')->assertJsonCount(0, 'users');
        $this->search('u0015')->assertJsonCount(0, 'users');
    }

    public function test_leaves_out_a_department_that_is_not_in_the_tree(): void
    {
        $outside = $this->department($this->departmentSet());
        $this->nameDepartment($outside, '孤立部');

        $this->search('孤立')->assertJsonCount(0, 'departments');
    }

    public function test_returns_at_most_ten_of_each_in_the_order_of_the_sort_key_and_the_code(): void
    {
        $extra = $this->department($this->departmentSet(), sortKey: 50);
        $this->include(Department::query()->where('code', 'deliver')->sole(), $extra);
        $this->nameDepartment($extra, '監査部');

        $response = $this->search('部');
        $this->assertSame(
            ['administration', 'general-affairs', 'accounting', 'human-resources', 'sales', 'sales-1', 'sales-2', 'engineering', 'development', 'quality-assurance'],
            $this->codes($response, 'departments'),
        );
        $this->assertSame(
            ['u0001', 'u0002', 'u0003', 'u0004', 'u0005', 'u0006', 'u0007', 'u0008', 'u0009', 'u0010'],
            $this->codes($this->search('u00'), 'users'),
        );
    }

    #[TestWith(['%'])]
    #[TestWith(['_'])]
    #[TestWith(['\\'])]
    #[TestWith(['u00_9'])]
    public function test_wildcards_in_the_keyword_are_plain_characters(string $keyword): void
    {
        $this->search($keyword)->assertExactJson(['users' => [], 'departments' => []]);
    }

    public function test_the_main_department_is_null_without_a_main_membership_in_the_tree(): void
    {
        $user = $this->user();
        UserTermTranslation::factory()->create(['user_term_id' => $user->userTerms()->sole()->id, 'locale_id' => $this->localeId('ja'), 'name' => '所属 なし']);

        $this->search('所属 なし')->assertJsonPath('users', [['id' => $user->id, 'code' => $user->code, 'name' => '所属 なし', 'mainDepartment' => null]]);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_a_missing_or_blank_keyword_does_not_narrow_down_and_returns_the_first_ten_of_each(string $keyword): void
    {
        $first = $this->search('u00');
        $expectedUsers = $this->codes($first, 'users');
        $this->assertCount(10, $expectedUsers);

        foreach ([$this->search($keyword), $this->getJson('/master/search')] as $response) {
            $response->assertOk();
            $this->assertSame($expectedUsers, $this->codes($response, 'users'));
            $this->assertSame(
                ['deliver', 'planning', 'administration', 'general-affairs', 'accounting', 'human-resources', 'sales', 'sales-1', 'tokyo-sales', 'sales-2'],
                $this->codes($response, 'departments'),
            );
        }
    }

    public function test_an_empty_keyword_still_leaves_out_users_not_effective_today_and_departments_outside_the_tree(): void
    {
        $outside = $this->department($this->departmentSet());
        $this->nameDepartment($outside, '孤立部');

        $codes = $this->codes($this->search(''), 'users');

        $this->assertNotContains('u0014', $codes);
        $this->assertNotContains('u0015', $codes);
        $this->assertNotContains($outside->code, $this->codes($this->search(''), 'departments'));
    }

    public function test_rejects_a_keyword_longer_than_100_characters(): void
    {
        $this->search(str_repeat('あ', 100))->assertOk();
        $this->search(str_repeat('あ', 101))->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }

    public function test_rejects_a_keyword_given_as_an_array(): void
    {
        $this->getJson('/master/search?q[]=u0009')->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }

    public function test_rejects_a_keyword_that_is_not_valid_utf8(): void
    {
        $this->search("\xFF")->assertUnprocessable()->assertJsonValidationErrors(['q' => '検索する語の指定が正しくありません']);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function search(string $keyword): TestResponse
    {
        return $this->getJson('/master/search?'.http_build_query(['q' => $keyword]));
    }

    /**
     * @param  TestResponse<JsonResponse>  $response
     * @return list<string>
     */
    private function codes(TestResponse $response, string $key): array
    {
        return array_column((array) $response->json($key), 'code');
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function departmentSet(): DepartmentSet
    {
        return Department::query()->where('code', 'deliver')->sole()->departmentSet()->sole();
    }

    private function localeId(string $code): int
    {
        return Locale::query()->where('code', $code)->sole()->id;
    }

    private function translationOfUser(string $code, string $localeCode): UserTermTranslation
    {
        return UserTermTranslation::query()
            ->where('locale_id', $this->localeId($localeCode))
            ->whereRelation('userTerm.user', 'code', $code)
            ->sole();
    }

    private function translationOfDepartment(string $code): DepartmentTermTranslation
    {
        return DepartmentTermTranslation::query()
            ->where('locale_id', $this->localeId('ja'))
            ->whereRelation('departmentTerm.department', 'code', $code)
            ->sole();
    }

    private function nameDepartment(Department $department, string $name): void
    {
        DepartmentTermTranslation::factory()->create([
            'department_term_id' => $department->departmentTerms()->sole()->id,
            'locale_id' => $this->localeId('ja'),
            'name' => $name,
        ]);
    }
}

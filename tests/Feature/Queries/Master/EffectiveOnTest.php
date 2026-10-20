<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Authz\UserRole;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\UserTerm;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 「基準日 d に有効な行」の定義(docs の 設計/共通マスタ/共通の規則.md 5.4)を、期間の列を持つ 6 表すべてで確かめる
 * (半開区間 = 開始日を含み終了日を含まない、終了日 NULL = 上限なし、無効の旗の行は有効でない)。
 */
final class EffectiveOnTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    /**
     * 表 → [モデル, d に有効な行を引く問い合わせ]。
     *
     * @return array<string, array{class-string<Model>, Closure(CarbonImmutable): Builder<covariant Model>}>
     */
    public static function periodModels(): array
    {
        return [
            'department_terms' => [DepartmentTerm::class, fn (CarbonImmutable $day) => DepartmentTerm::query()->effectiveOn($day)],
            'company_post_terms' => [CompanyPostTerm::class, fn (CarbonImmutable $day) => CompanyPostTerm::query()->effectiveOn($day)],
            'user_terms' => [UserTerm::class, fn (CarbonImmutable $day) => UserTerm::query()->effectiveOn($day)],
            'department_inclusions' => [DepartmentInclusion::class, fn (CarbonImmutable $day) => DepartmentInclusion::query()->effectiveOn($day)],
            'department_memberships' => [DepartmentMembership::class, fn (CarbonImmutable $day) => DepartmentMembership::query()->effectiveOn($day)],
            'user_roles' => [UserRole::class, fn (CarbonImmutable $day) => UserRole::query()->effectiveOn($day)],
        ];
    }

    /**
     * @return array<string, array{class-string<Model>, Closure(CarbonImmutable): Builder<covariant Model>, string, bool}>
     */
    public static function boundaries(): array
    {
        $cases = [];

        foreach (self::periodModels() as $table => [$model, $effectiveOn]) {
            $cases["{$table}: 開始日の前日"] = [$model, $effectiveOn, '2024-03-31', false];
            $cases["{$table}: 開始日"] = [$model, $effectiveOn, '2024-04-01', true];
            $cases["{$table}: 終了日の前日"] = [$model, $effectiveOn, '2025-03-31', true];
            $cases["{$table}: 終了日"] = [$model, $effectiveOn, '2025-04-01', false];
        }

        return $cases;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(CarbonImmutable): Builder<covariant Model>  $effectiveOn
     */
    #[DataProvider('boundaries')]
    public function test_a_row_is_effective_from_its_start_date_until_the_day_before_its_end_date(string $model, Closure $effectiveOn, string $date, bool $expected): void
    {
        $row = Factory::factoryForModel($model)->createOne(['start_date' => '2024-04-01', 'end_date' => '2025-04-01']);

        $this->assertSame($expected, $effectiveOn(self::day($date))->whereKey($row->getKey())->exists());
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(CarbonImmutable): Builder<covariant Model>  $effectiveOn
     */
    #[DataProvider('periodModels')]
    public function test_a_row_without_an_end_date_is_effective_without_limit(string $model, Closure $effectiveOn): void
    {
        $row = Factory::factoryForModel($model)->createOne(['start_date' => '2024-04-01', 'end_date' => null]);

        $this->assertTrue($effectiveOn(self::day('9999-12-31'))->whereKey($row->getKey())->exists());
    }

    /**
     * @param  class-string<Model>  $model
     * @param  Closure(CarbonImmutable): Builder<covariant Model>  $effectiveOn
     */
    #[DataProvider('periodModels')]
    public function test_a_deleted_row_is_not_effective(string $model, Closure $effectiveOn): void
    {
        $row = Factory::factoryForModel($model)->createOne(['start_date' => '2024-04-01', 'end_date' => null, 'is_deleted' => true]);

        $this->assertFalse($effectiveOn(self::day('2024-04-01'))->whereKey($row->getKey())->exists());
    }

    public function test_the_time_of_the_date_is_ignored(): void
    {
        $row = DepartmentTerm::factory()->create(['start_date' => '2024-04-01', 'end_date' => '2025-04-01']);

        $this->assertTrue(DepartmentTerm::query()->whereKey($row->id)->effectiveOn(self::day('2025-03-31 23:59:59'))->exists());
        $this->assertFalse(DepartmentTerm::query()->whereKey($row->id)->effectiveOn(self::day('2024-03-31 23:59:59'))->exists());
    }
}

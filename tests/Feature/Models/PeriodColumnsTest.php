<?php

namespace Tests\Feature\Models;

use App\Models\Authz\UserRole;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\UserTerm;
use App\Models\Workflow\WorkflowFlowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 期間の列(docs の 設計/共通マスタ/共通の規則.md 5.1・5.2: 半開区間 [start_date, end_date)、end_date が NULL なら上限なし、
 * 長さ 0 の期間と、日付の特殊値 infinity・-infinity(この作業で決めた)を拒む。同じ業務キーの行どうしは、無効の行も含めて期間が重ならない)。
 * ワークフローのフローの版も同じ規則(業務キー = フロー。docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.1、直列化と検査.md 7 節の「版」)。
 */
final class PeriodColumnsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * モデル → [業務キーの列(排他制約の列の順), 同じ業務キーの行を作るときに写す列]。
     *
     * @var array<class-string<Model>, array{list<string>, list<string>}>
     */
    private const BUSINESS_KEYS = [
        DepartmentTerm::class => [['department_id'], ['department_id']],
        CompanyPostTerm::class => [['company_post_id'], ['company_post_id']],
        UserTerm::class => [['user_id'], ['user_id']],
        DepartmentInclusion::class => [['parent_department_id', 'department_id'], ['department_set_id', 'parent_department_id', 'department_id']],
        DepartmentMembership::class => [['user_id', 'department_id'], ['department_set_id', 'department_id', 'user_id']],
        UserRole::class => [['user_id', 'role_id'], ['user_id', 'role_id']],
        WorkflowFlowVersion::class => [['workflow_flow_id'], ['workflow_flow_id']],
    ];

    /**
     * @return array<string, array{class-string<Model>, list<string>, list<string>}>
     */
    public static function periodTables(): array
    {
        $cases = [];

        foreach (self::BUSINESS_KEYS as $model => [$key, $copied]) {
            $cases[class_basename($model)] = [$model, $key, $copied];
        }

        return $cases;
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function periodModels(): array
    {
        return array_map(fn (array $case): array => [$case[0]], self::periodTables());
    }

    /**
     * @return array<string, array{class-string<Model>, string, string}>
     */
    public static function periodsThatDoNotEndAfterTheStart(): array
    {
        return self::withValues(['同じ日' => ['2020-01-01', '2020-01-01'], '逆順' => ['2020-02-01', '2020-01-01']]);
    }

    /**
     * @return array<string, array{class-string<Model>, string, string}>
     */
    public static function infiniteDates(): array
    {
        return self::withValues([
            '開始日が infinity' => ['start_date', 'infinity'],
            '開始日が -infinity' => ['start_date', '-infinity'],
            '終了日が infinity' => ['end_date', 'infinity'],
            '終了日が -infinity' => ['end_date', '-infinity'],
        ]);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('periodsThatDoNotEndAfterTheStart')]
    public function test_rejects_a_period_that_does_not_end_after_it_starts(string $model, string $startDate, string $endDate): void
    {
        $table = (new $model)->getTable();

        $this->assertRejectedBy(
            "{$table}_start_date_end_date_check",
            fn () => Factory::factoryForModel($model)->createOne(['start_date' => $startDate, 'end_date' => $endDate]),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('infiniteDates')]
    public function test_rejects_an_infinite_date(string $model, string $column, string $value): void
    {
        $table = (new $model)->getTable();
        $row = [...Factory::factoryForModel($model)->makeOne()->getAttributes(), $column => $value];

        $this->assertRejectedBy("{$table}_start_date_end_date_check", fn () => DB::table($table)->insert($row));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $key
     * @param  list<string>  $copied
     */
    #[DataProvider('periodTables')]
    public function test_rejects_an_overlap_with_a_deleted_row_of_the_same_key(string $model, array $key, array $copied): void
    {
        $deleted = Factory::factoryForModel($model)->createOne(['start_date' => '2020-01-01', 'end_date' => '2021-01-01', 'is_deleted' => true]);
        $overlapping = [...$deleted->only($copied), 'start_date' => '2020-06-01', 'end_date' => null];

        $this->assertRejectedBy(
            $deleted->getTable().'_'.implode('_', $key).'_start_date_end_date_excl',
            fn () => Factory::factoryForModel($model)->createOne($overlapping),
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $key
     * @param  list<string>  $copied
     */
    #[DataProvider('periodTables')]
    public function test_accepts_the_next_period_starting_on_the_end_date(string $model, array $key, array $copied): void
    {
        $previous = Factory::factoryForModel($model)->createOne(['start_date' => '2020-01-01', 'end_date' => '2021-01-01']);

        $next = Factory::factoryForModel($model)->createOne([...$previous->only($copied), 'start_date' => '2021-01-01', 'end_date' => null]);

        $this->assertModelExists($next);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('periodModels')]
    public function test_accepts_the_same_period_for_another_key(string $model): void
    {
        Factory::factoryForModel($model)->createOne(['start_date' => '2020-01-01', 'end_date' => null]);

        $other = Factory::factoryForModel($model)->createOne(['start_date' => '2020-01-01', 'end_date' => null]);

        $this->assertModelExists($other);
    }

    /**
     * @param  array<string, array{string, string}>  $values
     * @return array<string, array{class-string<Model>, string, string}>
     */
    private static function withValues(array $values): array
    {
        $cases = [];

        foreach (array_keys(self::BUSINESS_KEYS) as $model) {
            foreach ($values as $label => [$first, $second]) {
                $cases[class_basename($model).' '.$label] = [$model, $first, $second];
            }
        }

        return $cases;
    }
}

<?php

namespace Tests\Feature\Models;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\Company;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentSet;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRelatedColumn;
use App\Models\Workflow\WorkflowFlow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ソートキー sort_key(docs の 設計/共通マスタ/共通の規則.md 4 節: bigint NOT NULL DEFAULT 0。
 * 値は本家の NUMERIC(15,0) の範囲 = 15 桁まで(この作業で決めた検査制約))。業務データの入れ物の定義の表も同じ(docs の
 * 設計/業務データの入れ物/データモデル/ 1 節。records は親の無い行の並びが 0 に限られるので tests/Feature/Models/Records/RecordTest.php)。
 * ワークフローのフローも同じ(docs の 設計/ワークフロー/データモデル/表.md 2.4、直列化と検査.md 6 節)。
 * レコードの共通機能の見出しと関連レコードの列も同じ(設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.6・2.7 節)。
 */
final class SortKeyColumnsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const MODELS = [
        Locale::class,
        Company::class,
        DepartmentSet::class,
        Department::class,
        DepartmentInclusion::class,
        CompanyPost::class,
        User::class,
        DepartmentMembership::class,
        Role::class,
        AuthzResourceGroup::class,
        AuthzSubjectGroup::class,
        AuthzSubjectCategory::class,
        RecordModule::class,
        RecordField::class,
        RecordFieldOption::class,
        RecordFieldHeading::class,
        RecordRelatedColumn::class,
        WorkflowFlow::class,
    ];

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function sortKeyTables(): array
    {
        $cases = [];

        foreach (self::MODELS as $model) {
            $cases[class_basename($model)] = [$model];
        }

        return $cases;
    }

    /**
     * @return array<string, array{class-string<Model>, int}>
     */
    public static function valuesBeyond15Digits(): array
    {
        return self::withValues(['10^15' => 1_000_000_000_000_000, '-10^15' => -1_000_000_000_000_000]);
    }

    /**
     * @return array<string, array{class-string<Model>, int}>
     */
    public static function valuesWithin15Digits(): array
    {
        return self::withValues(['10^15 - 1' => 999_999_999_999_999, '-(10^15 - 1)' => -999_999_999_999_999]);
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('valuesBeyond15Digits')]
    public function test_rejects_a_sort_key_beyond_15_digits(string $model, int $sortKey): void
    {
        $table = (new $model)->getTable();

        $this->assertRejectedBy("{$table}_sort_key_check", fn () => Factory::factoryForModel($model)->createOne(['sort_key' => $sortKey]));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('valuesWithin15Digits')]
    public function test_accepts_a_sort_key_within_15_digits(string $model, int $sortKey): void
    {
        $row = Factory::factoryForModel($model)->createOne(['sort_key' => $sortKey]);

        $this->assertSame($sortKey, $row->refresh()->getAttribute('sort_key'));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('sortKeyTables')]
    public function test_fills_zero_when_the_sort_key_is_omitted(string $model): void
    {
        $row = Factory::factoryForModel($model)->makeOne()->getAttributes();
        unset($row['sort_key']);
        $table = (new $model)->getTable();

        $id = DB::table($table)->insertGetId($row);

        $this->assertSame(0, $model::query()->findOrFail($id)->getAttribute('sort_key'));
    }

    /**
     * @param  array<string, int>  $values
     * @return array<string, array{class-string<Model>, int}>
     */
    private static function withValues(array $values): array
    {
        $cases = [];

        foreach (self::MODELS as $model) {
            foreach ($values as $label => $value) {
                $cases[class_basename($model).' '.$label] = [$model, $value];
            }
        }

        return $cases;
    }
}

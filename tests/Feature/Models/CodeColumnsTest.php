<?php

namespace Tests\Feature\Models;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectCategory;
use App\Models\Authz\Role;
use App\Models\Master\Company;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\Locale;
use App\Models\Master\User;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 本家のコードの列 code(docs の 設計/共通マスタ/共通の規則.md 2 節・4 節: 空文字を拒み、本家のキーの範囲で一意)。業務データの入れ物の
 * コードの列も同じ(空文字は書式の検査制約が拒む = 設計/業務データの入れ物/データモデル/ 1 節。書式そのものは tests/Feature/Models/Records/)。
 * ワークフローのコンテンツ・フロー・ルートのノード・案件のノードのコードも同じ(設計/ワークフロー/データモデル/README.md 1 節、直列化と検査.md 6 節。
 * 段 3a でルートの表を消し、ルートのノードは版の中で一意 = ルートの深さ/データモデル/README.md 1 節。案件のノードの code は展開の行で NULL だが、
 * 書式の検査制約は NULL を通す。書式そのものは tests/Feature/Models/Workflow/)。
 */
final class CodeColumnsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * モデル → 一意の範囲の列(全体で一意なら空)。
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const UNIQUE_SCOPES = [
        Locale::class => [],
        Company::class => [],
        DepartmentSet::class => ['company_id'],
        Department::class => ['department_set_id'],
        CompanyPost::class => ['department_set_id'],
        User::class => [],
        Role::class => [],
        AuthzResourceGroup::class => [],
        AuthzSubjectCategory::class => [],
        MenuGroupCategory::class => [],
        Menu::class => [],
        RecordModule::class => [],
        RecordField::class => ['record_module_id'],
        RecordFieldOption::class => ['record_field_id'],
        WorkflowContent::class => [],
        WorkflowFlow::class => [],
        WorkflowRouteNode::class => ['workflow_flow_version_id'],
        WorkflowCaseNode::class => ['workflow_case_id'],
    ];

    /**
     * @return array<string, array{class-string<Model>, list<string>}>
     */
    public static function codeTables(): array
    {
        $cases = [];

        foreach (self::UNIQUE_SCOPES as $model => $scope) {
            $cases[class_basename($model)] = [$model, $scope];
        }

        return $cases;
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function codeModels(): array
    {
        return array_map(fn (array $case): array => [$case[0]], self::codeTables());
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function scopedCodeModels(): array
    {
        return array_map(
            fn (array $case): array => [$case[0]],
            array_filter(self::codeTables(), fn (array $case): bool => $case[1] !== []),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('codeModels')]
    public function test_rejects_an_empty_code(string $model): void
    {
        $table = (new $model)->getTable();

        $this->assertRejectedBy("{$table}_code_check", fn () => Factory::factoryForModel($model)->createOne(['code' => '']));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $scope
     */
    #[DataProvider('codeTables')]
    public function test_rejects_a_duplicate_code_in_the_same_scope(string $model, array $scope): void
    {
        $existing = Factory::factoryForModel($model)->createOne();
        $sameScope = [...$existing->only($scope), 'code' => $existing->getAttribute('code')];

        $this->assertRejectedBy(
            $existing->getTable().'_'.implode('_', [...$scope, 'code']).'_unique',
            fn () => Factory::factoryForModel($model)->createOne($sameScope),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('scopedCodeModels')]
    public function test_accepts_the_same_code_in_another_scope(string $model): void
    {
        $existing = Factory::factoryForModel($model)->createOne();

        $other = Factory::factoryForModel($model)->createOne(['code' => $existing->getAttribute('code')]);

        $this->assertModelExists($other);
    }
}

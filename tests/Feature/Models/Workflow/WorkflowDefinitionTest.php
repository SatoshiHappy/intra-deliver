<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローの定義と、案件のノード(写した定義)の制約(docs の 設計/ワークフロー/データモデル/README.md 1 節、表.md 2.1〜2.4・2.6、
 * 直列化と検査.md 6 節の「書式」「指定の用途と申請フォーム」、段 3a の ルートの深さ/データモデル/定義の表.md 2.1・2.3): コードの書式・名前の空・
 * 先頭だけが申請のノード・位置の一意(ルートのノードは版の中)・明細のモジュールを申請フォームにしない・処理対象者と参照者の指定は用途 selection の対象者だけ・
 * 版の番号は 1 以上で、フローの中で一意。版の期間は tests/Feature/Models/PeriodColumnsTest.php、ノードの設定は WorkflowNodeSettingsTest。
 * コードの重なりの拒否は tests/Feature/Models/CodeColumnsTest.php、決まった値の一覧は WorkflowEnumValuesTest。
 */
final class WorkflowDefinitionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** コードを持つ表のモデル */
    private const CODE_MODELS = [WorkflowContent::class, WorkflowRouteNode::class, WorkflowFlow::class, WorkflowCaseNode::class];

    /** ノード(ルートのノードと、それを写した案件のノード)のモデル */
    private const NODE_MODELS = [WorkflowRouteNode::class, WorkflowCaseNode::class];

    /**
     * @return array<string, array{class-string<Model>, string}>
     */
    public static function malformedCodes(): array
    {
        $codes = ['大文字' => 'Expense', '先頭の数字' => '1expense', '先頭の _' => '_expense', '- を含む' => 'expense-report', '51 文字' => str_repeat('a', 51), '空' => ''];

        return self::forModels(self::CODE_MODELS, $codes);
    }

    /**
     * @return array<string, array{class-string<Model>, string}>
     */
    public static function wellFormedCodes(): array
    {
        return self::forModels(self::CODE_MODELS, ['1 文字' => 'a', '数字と _ を含む' => 'expense_2026', '50 文字' => str_repeat('a', 50)]);
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function namedModels(): array
    {
        return array_map(fn (string $model): array => [$model], self::keyedByName(self::CODE_MODELS));
    }

    /**
     * @return array<string, array{class-string<Model>, int, WorkflowNodeKind, string}>
     */
    public static function misplacedNodes(): array
    {
        $cases = [];

        foreach (self::NODE_MODELS as $model) {
            $name = class_basename($model);
            $table = (new $model)->getTable();
            $cases["{$name} 位置 1 の承認"] = [$model, 1, WorkflowNodeKind::Approve, "{$table}_position_kind_check"];
            $cases["{$name} 位置 2 の申請"] = [$model, 2, WorkflowNodeKind::Apply, "{$table}_position_kind_check"];
            $cases["{$name} 位置 0"] = [$model, 0, WorkflowNodeKind::Approve, "{$table}_position_check"];
        }

        return $cases;
    }

    /**
     * @return array<string, array{class-string<Model>}>
     */
    public static function nodeModels(): array
    {
        return array_map(fn (string $model): array => [$model], self::keyedByName(self::NODE_MODELS));
    }

    /**
     * 用途 selection の対象者を指す行のモデルと factory の状態。
     *
     * @return array<string, array{class-string<Model>, string|null}>
     */
    public static function selectionReferrers(): array
    {
        return [
            'WorkflowRouteNode' => [WorkflowRouteNode::class, null],
            'WorkflowFlow' => [WorkflowFlow::class, 'withReferrers'],
            'WorkflowCaseNode' => [WorkflowCaseNode::class, null],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('malformedCodes')]
    public function test_rejects_a_malformed_code(string $model, string $code): void
    {
        $table = (new $model)->getTable();

        $this->assertRejectedBy("{$table}_code_check", fn () => Factory::factoryForModel($model)->createOne(['code' => $code]));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('wellFormedCodes')]
    public function test_accepts_a_well_formed_code(string $model, string $code): void
    {
        $row = Factory::factoryForModel($model)->createOne(['code' => $code]);

        $this->assertSame($code, $row->refresh()->getAttribute('code'));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('namedModels')]
    public function test_rejects_an_empty_name(string $model): void
    {
        $table = (new $model)->getTable();

        $this->assertRejectedBy("{$table}_name_check", fn () => Factory::factoryForModel($model)->createOne(['name' => '']));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('misplacedNodes')]
    public function test_rejects_a_node_whose_position_and_kind_do_not_match(string $model, int $position, WorkflowNodeKind $kind, string $constraint): void
    {
        $this->assertRejectedBy($constraint, fn () => Factory::factoryForModel($model)->createOne(['position' => $position, 'kind' => $kind]));
    }

    /**
     * @param  class-string<WorkflowRouteNode|WorkflowCaseNode>  $model
     */
    #[DataProvider('nodeModels')]
    public function test_rejects_a_second_node_at_the_same_position(string $model): void
    {
        $existing = Factory::factoryForModel($model)->createOne();
        $parent = $existing instanceof WorkflowRouteNode ? 'workflow_flow_version_id' : 'workflow_case_id';

        $this->assertRejectedBy(
            "{$existing->getTable()}_{$parent}_position_unique",
            fn () => Factory::factoryForModel($model)->createOne([$parent => $existing->getAttribute($parent), 'position' => 1]),
        );
    }

    public function test_rejects_a_version_number_below_one(): void
    {
        $this->assertRejectedBy('workflow_flow_versions_number_check', fn () => WorkflowFlowVersion::factory()->createOne(['number' => 0]));
    }

    public function test_rejects_a_second_version_with_the_same_number_in_a_flow(): void
    {
        $version = WorkflowFlowVersion::factory()->createOne(['start_date' => '2020-01-01', 'end_date' => '2021-01-01']);

        $this->assertRejectedBy(
            'workflow_flow_versions_workflow_flow_id_number_unique',
            fn () => WorkflowFlowVersion::factory()->createOne(['workflow_flow_id' => $version->workflow_flow_id, 'number' => 1, 'start_date' => '2021-01-01']),
        );
    }

    public function test_rejects_a_content_whose_record_module_is_a_detail_module(): void
    {
        $detail = RecordModule::factory()->detail()->create();

        $this->assertRejectedBy('workflow_contents_record_module_id_is_detail_foreign', fn () => WorkflowContent::factory()->create(['record_module_id' => $detail->id]));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('selectionReferrers')]
    public function test_rejects_pointing_to_a_policy_subject_group(string $model, ?string $state): void
    {
        $policy = AuthzSubjectGroup::factory()->create(['purpose' => SubjectGroupPurpose::Policy]);
        $table = (new $model)->getTable();
        $factory = Factory::factoryForModel($model);

        $this->assertRejectedBy(
            "{$table}_authz_subject_group_id_purpose_foreign",
            fn () => ($state === null ? $factory : $factory->{$state}())->createOne(['authz_subject_group_id' => $policy->id]),
        );
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('selectionReferrers')]
    public function test_rejects_turning_a_pointed_selection_subject_group_into_a_policy_one(string $model, ?string $state): void
    {
        $factory = Factory::factoryForModel($model);
        $row = ($state === null ? $factory : $factory->{$state}())->createOne();

        $this->assertRejectedBy(
            "{$row->getTable()}_authz_subject_group_id_purpose_foreign",
            fn () => DB::table('authz_subject_groups')->where('id', $row->getAttribute('authz_subject_group_id'))->update(['purpose' => SubjectGroupPurpose::Policy->value]),
        );
    }

    /**
     * モデルごとに値を組にした provider の行。
     *
     * @param  list<class-string<Model>>  $models
     * @param  array<string, string>  $values
     * @return array<string, array{class-string<Model>, string}>
     */
    private static function forModels(array $models, array $values): array
    {
        $cases = [];

        foreach ($models as $model) {
            foreach ($values as $label => $value) {
                $cases[class_basename($model).' '.$label] = [$model, $value];
            }
        }

        return $cases;
    }

    /**
     * @param  list<class-string<Model>>  $models
     * @return array<string, class-string<Model>>
     */
    private static function keyedByName(array $models): array
    {
        return array_combine(array_map(class_basename(...), $models), $models);
    }
}

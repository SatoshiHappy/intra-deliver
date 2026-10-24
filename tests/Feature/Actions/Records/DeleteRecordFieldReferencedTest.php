<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\DeleteRecordField;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * ほかのモジュールの表の行が指す項目の削除(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節の
 * 「項目を消せるか」の表の 4 行目と優先順、設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 1): 消す文の外部キー違反を
 * 422 field に変え、消した行も巻き戻る。ほかのモジュールの表は、テストの中で消す行を RESTRICT で指す表を作って置く(テストの巻き戻しで消える)。
 * 表のほかの行(組み込み・規則・持ち主・消せる)は BuiltinDefinitionTest・RecordFieldDefinitionTest・RecordHoldRuleTest。
 */
final class DeleteRecordFieldReferencedTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    public function test_a_field_pointed_by_another_table_is_rejected_and_its_deleted_reference_values_come_back(): void
    {
        $projects = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $field = $this->referenceField($projects, 'customer', $customers, ['name' => '顧客']);
        $reference = $this->referTo($projects, $field, $customers);
        $this->pointAt('record_fields', $field->id);

        $errors = $this->validationErrors(fn () => app(DeleteRecordField::class)->execute('projects', 'customer'));

        $this->assertSame(['field' => '顧客はほかのデータから参照されているため削除できません'], $errors);
        $this->assertModelExists($field);
        $this->assertModelExists($reference);
    }

    public function test_a_reference_field_with_values_that_no_other_table_points_to_is_deleted(): void
    {
        $projects = $this->recordModule('projects');
        $customers = $this->recordModule('customers');
        $field = $this->referenceField($projects, 'customer', $customers);
        $reference = $this->referTo($projects, $field, $customers);

        app(DeleteRecordField::class)->execute('projects', 'customer');

        $this->assertModelMissing($field);
        $this->assertModelMissing($reference);
    }

    /**
     * 明細の項目は、明細の行・明細のモジュールの項目・明細のモジュールのどの消す文で違反が起きても 422 field で、何も消えない。
     */
    #[DataProvider('detailParts')]
    public function test_a_details_field_whose_parts_are_pointed_is_rejected(string $table): void
    {
        $projects = $this->recordModule('projects');
        $tasks = $this->detailsField($projects, 'tasks', ['name' => '作業']);
        $child = $this->childOf($tasks);
        $hours = $this->field($child, 'hours');
        $record = Record::factory()->create(['record_module_id' => $projects->id]);
        $row = Record::factory()->create([
            'record_module_id' => $child->id,
            'parent_record_module_id' => $projects->id,
            'parent_record_id' => $record->id,
        ]);
        $pointed = ['records' => $row->id, 'record_fields' => $hours->id, 'record_modules' => $child->id];
        $this->pointAt($table, $pointed[$table]);

        $errors = $this->validationErrors(fn () => app(DeleteRecordField::class)->execute('projects', 'tasks'));

        $this->assertSame(['field' => '作業はほかのデータから参照されているため削除できません'], $errors);
        foreach ([$tasks, $child, $row, $hours] as $model) {
            $this->assertModelExists($model);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function detailParts(): array
    {
        return ['明細の行' => ['records'], '明細のモジュールの項目' => ['record_fields'], '明細のモジュール' => ['record_modules']];
    }

    /**
     * 先の理由(組み込み・規則・持ち主)とほかの表の参照が両方当たるときは、先の理由の 422 だけを返す(11 節の表の優先順)。
     */
    #[DataProvider('earlierReasons')]
    public function test_an_earlier_reason_comes_before_the_pointer(string $reason, string $message): void
    {
        $projects = $this->recordModule('projects');
        $field = $this->field($projects, 'memo', attributes: ['is_builtin' => $reason === 'builtin']);
        $this->pointAt('record_fields', $field->id);
        $this->arrange($reason, $projects, $field);

        $errors = $this->validationErrors(fn () => app(DeleteRecordField::class)->execute('projects', 'memo'));

        $this->assertSame(['field' => $message], $errors);
        $this->assertModelExists($field);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function earlierReasons(): array
    {
        return [
            '組み込み' => ['builtin', '組み込みの項目は削除できません'],
            '規則' => ['rule', 'memoを使用する規則があります。先に規則を削除してください'],
            '持ち主' => ['hold', '申請済みのため変更できません'],
        ];
    }

    /**
     * 先の理由を置く(組み込みは項目を作るときに旗を立てる)。
     */
    private function arrange(string $reason, RecordModule $module, RecordField $field): void
    {
        if ($reason === 'rule') {
            RecordRule::factory()->create(['record_module_id' => $module->id, 'target_record_field_id' => $field->id]);
        }

        if ($reason === 'hold') {
            $held = Record::factory()->create(['record_module_id' => $module->id]);
            $this->registerRecordHolds([$held->id => 'workflow-case:1']);
        }
    }

    private function referTo(RecordModule $module, RecordField $field, RecordModule $referencedModule): RecordReference
    {
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $referenced = Record::factory()->create(['record_module_id' => $referencedModule->id]);

        return RecordReference::factory()->create([
            'record_module_id' => $module->id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            'referenced_record_module_id' => $referencedModule->id,
            'referenced_record_id' => $referenced->id,
        ]);
    }

    /**
     * ほかのモジュールの表の行が、業務データの入れ物の表の行を RESTRICT で指す(表はテストの中で作り、テストの巻き戻しで消える)。
     */
    private function pointAt(string $table, int $id): void
    {
        $pointers = "test_pointers_to_{$table}";
        $column = "pointed_id bigint NOT NULL REFERENCES {$table} (id) ON DELETE RESTRICT";
        DB::statement("CREATE TABLE {$pointers} ({$column})");
        DB::table($pointers)->insert(['pointed_id' => $id]);
    }
}

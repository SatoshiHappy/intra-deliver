<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\CreateRecordModule;
use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\UpdateRecordField;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use Closure;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 組み込みの旗の付いた定義の行(docs の 設計/業務ツール/共通データ.md の「入れ物の拡張」の「組み込みの旗」): 項目の削除と、選択肢を外す項目の更新を
 * 422 で拒み、何も消さない。一緒に消える行(選択肢・明細のモジュールとその項目・選択肢)に旗があっても項目の削除を拒む。ほかの変更は拒まず、
 * 管理の画面の入力からは旗を立てられない。
 */
final class BuiltinDefinitionTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const FIELD_MESSAGE = '組み込みの項目は削除できません';

    public function test_rejects_deleting_a_builtin_field_and_keeps_it(): void
    {
        $module = $this->recordModule('meetings');
        $builtin = $this->field($module, 'title', attributes: ['is_builtin' => true]);
        $added = $this->field($module, 'memo');

        $errors = $this->validationErrors(fn () => $this->deleteField('meetings', 'title'));
        $this->deleteField('meetings', 'memo');

        $this->assertSame(['field' => self::FIELD_MESSAGE], $errors);
        $this->assertModelExists($builtin);
        $this->assertModelMissing($added);
    }

    /**
     * 旗の無い項目でも、一緒に消える行のどれかに旗があれば拒み、項目・選択肢・明細のモジュールとその項目を残す。
     */
    public function test_rejects_deleting_a_field_whose_parts_are_builtin(): void
    {
        foreach ($this->fieldsWithBuiltinParts() as $label => $build) {
            $module = $this->recordModule("meetings_{$label}");
            $field = $build($module);
            $rows = $this->definitionRowCounts();

            $errors = $this->validationErrors(fn () => $this->deleteField($module->code, $field->code));

            $this->assertSame(['field' => self::FIELD_MESSAGE], $errors, $label);
            $this->assertSame($rows, $this->definitionRowCounts(), $label);
        }
    }

    public function test_rejects_removing_a_builtin_option_and_removes_others(): void
    {
        $module = $this->recordModule('meetings');
        $field = $this->choiceField($module, 'kind', ['regular', 'adhoc', 'review']);
        $field->recordFieldOptions()->where('code', 'regular')->update(['is_builtin' => true]);

        $errors = $this->validationErrors(fn () => $this->updateOptions($field, ['adhoc', 'review']));
        $this->updateOptions($field, ['regular', 'adhoc']);

        $this->assertSame(['options' => '組み込みの選択肢は削除できません'], $errors);
        $this->assertSame(['adhoc', 'regular'], $field->recordFieldOptions()->orderBy('code')->pluck('code')->all());
    }

    public function test_changes_the_other_attributes_of_a_builtin_field(): void
    {
        $module = $this->recordModule('meetings');
        $this->field($module, 'body', attributes: ['is_builtin' => true, 'is_multiline' => true]);
        $this->field($module, 'viewers', RecordFieldType::User, ['is_builtin' => true, 'is_multiple' => true]);
        $update = app(UpdateRecordField::class);

        $attributes = ['name' => '本文', 'is_required' => true, 'is_listed' => true, 'max_length' => 9000];

        $body = $update->execute('meetings', 'body', $attributes);
        $viewers = $update->execute('meetings', 'viewers', ['grants_row_access' => true]);

        $this->assertSame(
            ['本文', true, true, 9000, true],
            [$body->name, $body->is_required, $body->is_listed, $body->max_length, $body->is_builtin],
        );
        $this->assertTrue($viewers->grants_row_access);
    }

    public function test_the_admin_input_does_not_raise_the_flag(): void
    {
        app(CreateRecordModule::class)->execute(['code' => 'meetings', 'name' => '会議', 'is_builtin' => true]);
        $input = ['code' => 'title', 'name' => '件名', 'type' => 'string', 'is_builtin' => true];
        $field = app(AddRecordField::class)->execute('meetings', $input);

        $this->assertFalse(RecordModule::query()->where('code', 'meetings')->sole()->is_builtin);
        $this->assertFalse($field->refresh()->is_builtin);
    }

    /**
     * 旗の付いた行を一緒に消える行に持つ、旗の無い項目の作り方(ラベル → 作り方)。
     *
     * @return array<string, Closure(RecordModule): RecordField>
     */
    private function fieldsWithBuiltinParts(): array
    {
        $builtinOption = function (RecordField $field): RecordField {
            RecordFieldOption::query()->where('record_field_id', $field->id)->update(['is_builtin' => true]);

            return $field;
        };

        return [
            'option' => fn (RecordModule $module) => $builtinOption($this->choiceField($module, 'kind', ['regular'])),
            'child_module' => function (RecordModule $module): RecordField {
                $field = $this->detailsField($module, 'module_items');
                $this->childOf($field)->forceFill(['is_builtin' => true])->save();

                return $field;
            },
            'child_field' => function (RecordModule $module): RecordField {
                $field = $this->detailsField($module, 'field_items');
                $this->field($this->childOf($field), 'name', attributes: ['is_builtin' => true]);

                return $field;
            },
            'child_option' => function (RecordModule $module) use ($builtinOption): RecordField {
                $field = $this->detailsField($module, 'option_items');
                $builtinOption($this->choiceField($this->childOf($field), 'kind', ['regular']));

                return $field;
            },
        ];
    }

    /**
     * 業務モジュール・項目・選択肢の行の数。
     *
     * @return list<int>
     */
    private function definitionRowCounts(): array
    {
        return [RecordModule::query()->count(), RecordField::query()->count(), RecordFieldOption::query()->count()];
    }

    private function deleteField(string $moduleCode, string $fieldCode): void
    {
        app(DeleteRecordField::class)->execute($moduleCode, $fieldCode);
    }

    /**
     * @param  list<string>  $codes  残す選択肢のコード(名称はコードと同じ)
     */
    private function updateOptions(RecordField $field, array $codes): void
    {
        $options = array_map(fn (string $code) => ['code' => $code, 'name' => $code], $codes);
        app(UpdateRecordField::class)->execute('meetings', $field->code, ['options' => $options]);
    }
}

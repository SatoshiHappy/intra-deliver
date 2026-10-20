<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\UpdateRecordField;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 項目の名前の重複の禁止(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節。ユーザーレビュー 03 の 18): 項目の名前は、同じ業務モジュールとその
 * 明細のモジュール(親と全部の明細 = 1 つの名前の集まり)の中で重複させない。業務モジュールをまたぐ重複は許す。比べ方は前後の空白を除いた後の文字列の
 * 完全一致(大文字小文字・全角半角は区別する)。項目を追加するときと、名前を変えるときだけ確かめる(名前が今の値のままなら確かめない)。
 * 失敗は 422(ValidationException)でキー name。表の一意の制約は無い(親と明細をまたぐため)。
 */
final class RecordFieldNameUniquenessTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    public function test_rejects_adding_a_field_with_a_name_of_the_same_module_and_writes_nothing(): void
    {
        $this->orders();
        $fields = RecordField::query()->count();

        $errors = $this->validationErrors(fn () => $this->add('orders', ['code' => 'subject2', 'name' => '件名', 'type' => 'string']));

        $this->assertSame($this->duplicate('orders'), $errors);
        $this->assertSame($fields, RecordField::query()->count());
    }

    public function test_compares_the_name_after_the_trim_and_distinguishes_the_case_and_the_width(): void
    {
        $this->orders();
        $this->field($this->recordModule('mixed'), 'status', attributes: ['name' => 'Status']);

        $trimmed = $this->validationErrors(fn () => $this->add('mixed', ['code' => 'status2', 'name' => '  Status ', 'type' => 'string']));
        $lower = $this->add('mixed', ['code' => 'status3', 'name' => 'status', 'type' => 'string']);
        $fullWidth = $this->add('mixed', ['code' => 'status4', 'name' => 'Ｓtatus', 'type' => 'string']);

        $this->assertSame($this->duplicate('mixed'), $trimmed);
        $this->assertSame(['status', 'Ｓtatus'], [$lower->name, $fullWidth->name]);
    }

    public function test_the_parent_and_every_details_module_share_one_set_of_names(): void
    {
        $orders = $this->orders();
        $lines = $this->childOf($this->detailsField($orders, 'lines'));
        $notes = $this->childOf($this->detailsField($orders, 'notes'));
        $this->field($lines, 'quantity', RecordFieldType::Integer, ['name' => '数量']);

        // 明細に、親と同じ名前
        $this->assertSame($this->duplicate('orders'), $this->validationErrors(fn () => $this->add('lines_rows', ['code' => 'subject', 'name' => '件名', 'type' => 'string'])));
        // 親に、明細と同じ名前
        $this->assertSame($this->duplicate('lines_rows'), $this->validationErrors(fn () => $this->add('orders', ['code' => 'quantity', 'name' => '数量', 'type' => 'integer'])));
        // 明細どうし
        $this->assertSame($this->duplicate('lines_rows'), $this->validationErrors(fn () => $this->add('notes_rows', ['code' => 'quantity', 'name' => '数量', 'type' => 'integer'])));
        // 明細の項目(型 = 明細)の名前も、同じ集まりの中で重複させない
        $this->assertSame($this->duplicate('lines_rows'), $this->validationErrors(fn () => $this->add('orders', ['code' => 'extra', 'name' => '数量', 'type' => 'details', 'child_module' => ['code' => 'extra_rows', 'name' => '追加']])));
        $this->assertSame(0, RecordModule::query()->where('code', 'extra_rows')->count());
        $this->assertSame(0, RecordField::query()->where('record_module_id', $notes->id)->count());
    }

    public function test_allows_the_same_name_in_another_module_and_its_details(): void
    {
        $orders = $this->orders();
        $this->detailsField($orders, 'lines');
        $other = $this->recordModule('invoices');

        $first = $this->add('invoices', ['code' => 'subject', 'name' => '件名', 'type' => 'string']);
        $second = $this->add('lines_rows', ['code' => 'memo', 'name' => '備考', 'type' => 'string']);
        $third = $this->add('invoices', ['code' => 'memo', 'name' => '備考', 'type' => 'string']);

        $this->assertSame(['件名', '備考', '備考'], [$first->name, $second->name, $third->name]);
        $this->assertSame($other->id, $first->record_module_id);
    }

    public function test_adds_a_details_field_whose_new_details_module_has_no_fields_yet(): void
    {
        $this->orders();

        $field = $this->add('orders', ['code' => 'lines', 'name' => '明細行', 'type' => 'details', 'child_module' => ['code' => 'lines_rows', 'name' => '明細行']]);

        $this->assertSame('明細行', $field->name);
    }

    public function test_rejects_renaming_a_field_to_a_name_of_the_same_set_but_not_a_change_of_other_attributes_or_the_same_name(): void
    {
        $orders = $this->orders();
        $lines = $this->childOf($this->detailsField($orders, 'lines'));
        $this->field($lines, 'quantity', RecordFieldType::Integer, ['name' => '数量']);

        $this->assertSame($this->duplicate('orders'), $this->validationErrors(fn () => $this->change('orders', 'amount', ['name' => '件名'])));
        $this->assertSame($this->duplicate('lines_rows'), $this->validationErrors(fn () => $this->change('orders', 'amount', ['name' => '数量'])));
        $this->assertSame($this->duplicate('orders'), $this->validationErrors(fn () => $this->change('lines_rows', 'quantity', ['name' => '件名'])));
        $this->assertSame('金額', RecordField::query()->where('code', 'amount')->sole()->name);

        // 名前が今のままなら通る(名前以外の変更も、同じ名前を渡しても)
        $same = $this->change('orders', 'amount', ['name' => '金額', 'is_listed' => true]);
        $other = $this->change('orders', 'amount', ['is_searchable' => true]);
        $renamed = $this->change('orders', 'amount', ['name' => '請求金額']);

        $this->assertSame(['金額', true], [$same->name, $same->is_listed]);
        $this->assertTrue($other->is_searchable);
        $this->assertSame('請求金額', $renamed->name);
    }

    public function test_does_not_refuse_a_change_other_than_the_name_of_a_field_that_already_has_a_duplicate_name(): void
    {
        $orders = $this->orders();
        $this->field($orders, 'subject_copy', attributes: ['name' => '件名']);

        $changed = $this->change('orders', 'subject_copy', ['is_listed' => true]);

        $this->assertTrue($changed->is_listed);
        $this->assertSame('件名', $changed->name);
        $this->assertSame($this->duplicate('orders'), $this->validationErrors(fn () => $this->change('orders', 'subject_copy', ['name' => '金額'])));
    }

    public function test_a_name_that_is_not_a_valid_text_keeps_the_text_message_and_is_not_compared(): void
    {
        $this->orders();

        $this->assertSame(['name' => '名前を入力してください'], $this->validationErrors(fn () => $this->add('orders', ['code' => 'memo', 'name' => '   ', 'type' => 'string'])));
    }

    /**
     * 名前の重複の誤り(module = 重複する項目のある業務モジュールの名前。テストの業務モジュールの名前はコードと同じ)。
     *
     * @return array{name: string}
     */
    private function duplicate(string $module): array
    {
        return ['name' => "この名前は「{$module}」の項目で既に使用されています"];
    }

    /**
     * 業務モジュール orders(項目 件名・金額)。
     */
    private function orders(): RecordModule
    {
        $module = $this->recordModule('orders');
        $this->field($module, 'subject', attributes: ['name' => '件名']);
        $this->field($module, 'amount', RecordFieldType::Integer, ['name' => '金額']);

        return $module;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function add(string $moduleCode, array $input): RecordField
    {
        return app(AddRecordField::class)->execute($moduleCode, $input);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function change(string $moduleCode, string $fieldCode, array $input): RecordField
    {
        return app(UpdateRecordField::class)->execute($moduleCode, $fieldCode, $input);
    }
}

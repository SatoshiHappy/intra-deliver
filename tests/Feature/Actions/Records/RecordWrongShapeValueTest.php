<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 型の形に合わない保存された値と規則(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 6〜8 の注記):
 * 保存された値が 2 節の型の形に合わない項目があるレコードを、その項目に触れずに更新すると、その項目の型の誤りだけが返り、その項目を
 * 対象・相手・条件に使う規則の失敗は返らず、ほかの規則の失敗は一緒に返る(例外にならない)。長さ・選択肢だけの誤りでは規則を外さない。
 */
final class RecordWrongShapeValueTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    public function test_rules_using_a_field_with_a_wrong_shaped_stored_value_are_not_evaluated_and_other_rules_are(): void
    {
        $module = $this->recordModule('projects');
        $budget = $this->field($module, 'budget', RecordFieldType::Integer);
        $limit = $this->field($module, 'limit', RecordFieldType::Integer);
        $note = $this->field($module, 'note');
        $start = $this->field($module, 'start', RecordFieldType::Date);
        $end = $this->field($module, 'end', RecordFieldType::Date);
        $this->compare($module, $limit, RecordComparisonOperator::Gt, $budget);
        $this->requiredWhen($module, $note, ['all' => [['field' => 'budget', 'op' => 'not_empty']]]);
        $this->compare($module, $end, RecordComparisonOperator::Ge, $start);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [
            $budget->id => '多い', $limit->id => 5, $start->id => '2026-10-11', $end->id => '2026-10-01',
        ]]);

        $errors = $this->validationErrors(fn () => $this->update($module, $record, ['limit' => 6]));

        $this->assertSame(['values.budget' => 'budgetは整数で入力してください', 'values.end' => 'endはstart以上にしてください'], $errors);
    }

    /**
     * 小数の桁の超過(保存された値が正規の形の桁を超える)は値の形の誤り: その項目の型の誤りだけが返り、その項目を使う規則は評価しない。
     */
    public function test_a_decimal_beyond_the_digits_is_a_wrong_shape_and_excludes_the_rules_using_it(): void
    {
        $module = $this->recordModule('projects');
        $rate = $this->field($module, 'rate', RecordFieldType::Decimal);
        $limit = $this->field($module, 'limit', RecordFieldType::Decimal);
        $this->compare($module, $limit, RecordComparisonOperator::Gt, $rate);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$rate->id => '1234567890123456.5', $limit->id => '1']]);

        $errors = $this->validationErrors(fn () => $this->update($module, $record, []));

        $this->assertSame(['values.rate' => 'rateは整数部 15 桁・小数部 4 桁までの数で入力してください'], $errors);
    }

    /**
     * 明細の行でも同じ(行ごとに、型の形に合わない値の項目を使う規則だけを外す)。
     */
    public function test_the_same_holds_for_a_detail_row(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $child = $this->childOf($tasks);
        $due = $this->field($child, 'due', RecordFieldType::Date);
        $done = $this->field($child, 'done_on', RecordFieldType::Date);
        $hours = $this->field($child, 'hours', RecordFieldType::Integer, ['is_required' => true]);
        $this->compare($child, $done, RecordComparisonOperator::Le, $due);
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        Record::factory()->create(['record_module_id' => $child->id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $record->id, 'sort_key' => 0,
            'field_values' => [$due->id => '10月1日', $done->id => '2026-10-11']]);

        $errors = $this->validationErrors(fn () => $this->update($module, $record, []));

        $this->assertSame(['details.tasks.0.values.due' => 'dueは日付(YYYY-MM-DD)で入力してください', 'details.tasks.0.values.hours' => 'hoursを入力してください'], $errors);
    }

    /**
     * 長さ・選択肢だけの誤り(型の形には合う)では、その項目を使う規則も評価して一緒に返す。
     */
    public function test_a_length_or_choice_error_does_not_exclude_the_rules_using_the_field(): void
    {
        $module = $this->recordModule('projects');
        $title = $this->field($module, 'title', attributes: ['max_length' => 3]);
        $status = $this->choiceField($module, 'status', ['open', 'done']);
        $note = $this->field($module, 'note');
        $owner = $this->field($module, 'owner');
        $this->requiredWhen($module, $note, ['all' => [['field' => 'title', 'op' => 'not_empty']]]);
        $this->requiredWhen($module, $owner, ['all' => [['field' => 'status', 'op' => 'not_empty']]]);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => '長いタイトル', $status->id => 'closed']]);

        $errors = $this->validationErrors(fn () => $this->update($module, $record, []));

        $this->assertSame([
            'values.title' => 'titleは 3 文字以内で入力してください',
            'values.status' => 'statusの値が正しくありません',
            'values.note' => 'noteを入力してください',
            'values.owner' => 'ownerを入力してください',
        ], $errors);
    }

    /**
     * 文字列でない選択(保存された値が配列)は値の形の誤り: その項目の型の誤りだけが返り、その項目を使う規則は評価しない
     * (選択肢の誤りは、今の選択肢に無いコードだけ)。
     */
    public function test_a_choice_that_is_not_a_string_is_a_wrong_shape_and_excludes_the_rules_using_it(): void
    {
        $module = $this->recordModule('projects');
        $status = $this->choiceField($module, 'status', ['open', 'done']);
        $note = $this->field($module, 'note');
        $this->requiredWhen($module, $note, ['all' => [['field' => 'status', 'op' => 'not_empty']]]);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$status->id => ['open']]]);

        $errors = $this->validationErrors(fn () => $this->update($module, $record, []));

        $this->assertSame(['values.status' => 'statusの値が正しくありません'], $errors);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(RecordModule $module, Record $record, array $values): void
    {
        app(UpdateRecord::class)->execute($this->contextFor(), $module->code, $record->id, new RecordInput($values), $record->refresh()->version());
    }

    private function compare(RecordModule $module, RecordField $target, RecordComparisonOperator $operator, RecordField $compared): void
    {
        RecordRule::factory()->create(['record_module_id' => $module->id, 'kind' => RecordRuleKind::Compare, 'target_record_field_id' => $target->id, 'condition' => null, 'operator' => $operator, 'compared_record_field_id' => $compared->id]);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function requiredWhen(RecordModule $module, RecordField $target, array $condition): void
    {
        RecordRule::factory()->create(['record_module_id' => $module->id, 'kind' => RecordRuleKind::RequiredWhen, 'target_record_field_id' => $target->id, 'condition' => $condition]);
    }
}

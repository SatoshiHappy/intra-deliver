<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\AddRecordRule;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\Record;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use App\Queries\Records\Api\RecordConditions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use Tests\Feature\Actions\Records\RecordRuleEvaluationTest;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 規則の条件の公開の口(docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節、定義の表.md 2.4、設計/ワークフロー/ルートの深さ/README.md の「実装の区切り」3a-1 の 1 の通す検査):
 * 評価が条件つき必須と同じ結果、今の定義に合わない式(項目が無い・明細・演算子が型に使えない・写した値が今の型の正規の形でない = C-R11)は空として評価、
 * 消えた選択肢は写した値のまま比べる、validate が規則の保存と同じ検証と正規化を返す。
 */
final class RecordConditionsTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use CatchesValidationErrors;

    /**
     * 拒む条件(規則の保存と同じキーと文言になる)。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedConditions(): array
    {
        return [
            'all の無い条件' => [['any' => []]],
            '無い項目と型に使えない演算子' => [['all' => [['field' => 'nothing', 'op' => 'eq', 'value' => 'a'], ['field' => 'title', 'op' => 'lt', 'value' => 'a']]]],
            '空白だけの値' => [['all' => [['field' => 'title', 'op' => 'eq', 'value' => '   ']]]],
            '整数の型が違う値' => [['all' => [['field' => 'budget', 'op' => 'ge', 'value' => '1.5']]]],
        ];
    }

    /**
     * 同じコードで型を変えて作り直した項目に、前の型で書いた条件が当たる組(C-R11 の回答の「テストで確かめる組み合わせ」の 5 件)。
     * [前の型, 前の選択肢, 書いた式, 今の型, 今の選択肢, レコードの今の値, 期待する真偽]
     *
     * @return array<string, array{RecordFieldType, list<string>, array<string, mixed>, RecordFieldType, list<string>, mixed, bool}>
     */
    public static function recreatedFields(): array
    {
        return [
            '1 整数の eq を文字列で作り直す' => [RecordFieldType::Integer, [], ['op' => 'eq', 'value' => 100], RecordFieldType::String, [], '100', false],
            '1 整数の ne を文字列で作り直す' => [RecordFieldType::Integer, [], ['op' => 'ne', 'value' => 100], RecordFieldType::String, [], '100', false],
            '1 値を持たない not_empty は真のまま' => [RecordFieldType::Integer, [], ['op' => 'not_empty'], RecordFieldType::String, [], '100', true],
            '2 整数の gt を小数で作り直す' => [RecordFieldType::Integer, [], ['op' => 'gt', 'value' => 5], RecordFieldType::Decimal, [], '7.5', false],
            '3 小数の eq を整数で作り直す' => [RecordFieldType::Decimal, [], ['op' => 'eq', 'value' => '100'], RecordFieldType::Integer, [], 100, false],
            '4 選択の eq を done の無い選択肢で作り直す' => [RecordFieldType::Choice, ['open', 'done'], ['op' => 'eq', 'value' => 'done'], RecordFieldType::Choice, ['open'], 'open', false],
            '4 選択の ne を done の無い選択肢で作り直す' => [RecordFieldType::Choice, ['open', 'done'], ['op' => 'ne', 'value' => 'done'], RecordFieldType::Choice, ['open'], 'open', true],
            '5 日付の le を日時で作り直す' => [RecordFieldType::Date, [], ['op' => 'le', 'value' => '2026-10-16'], RecordFieldType::Datetime, [], '2026-10-16T00:00:00Z', false],
            '演算子が今の型に使えない(整数の lt を文字列で)' => [RecordFieldType::Integer, [], ['op' => 'lt', 'value' => 10], RecordFieldType::String, [], 'abc', false],
            '演算子が今の型に使えない式の empty は真' => [RecordFieldType::Choice, ['open'], ['op' => 'empty'], RecordFieldType::Boolean, [], true, true],
        ];
    }

    #[DataProviderExternal(RecordRuleEvaluationTest::class, 'expressions')]
    public function test_matches_gives_the_same_result_as_the_conditional_requirement(RecordFieldType $type, string $operator, mixed $expected, mixed $input, bool $holds): void
    {
        $module = $this->recordModule('projects');
        $target = $this->field($module, 'target');

        if ($type === RecordFieldType::Choice) {
            $this->choiceField($module, 'f', ['open', 'done', 'hold']);
        } else {
            $this->field($module, 'f', $type);
        }

        $condition = ['all' => [in_array($operator, ['empty', 'not_empty'], true) ? ['field' => 'f', 'op' => $operator] : ['field' => 'f', 'op' => $operator, 'value' => $expected]]];
        $values = $input === null ? [] : ['f' => $input];
        RecordRule::factory()->create(['record_module_id' => $module->id, 'kind' => RecordRuleKind::RequiredWhen, 'target_record_field_id' => $target->id, 'condition' => $condition]);

        $ruleHolds = $this->createErrors($module, $values) !== [];
        $recordId = $this->create($module, [...$values, 'target' => 'x']);

        $this->assertSame([$holds, $holds], [$ruleHolds, $this->conditions()->matches($recordId, $condition)]);
    }

    public function test_a_reference_is_tested_for_emptiness_by_its_target(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'owner', RecordFieldType::User);
        $condition = ['all' => [['field' => 'owner', 'op' => 'not_empty']]];

        $this->assertTrue($this->conditions()->matches($this->create($module, ['owner' => $this->user()->id]), $condition));
        $this->assertFalse($this->conditions()->matches($this->create($module, []), $condition));
    }

    public function test_an_expression_on_a_deleted_field_or_a_details_field_is_evaluated_as_empty(): void
    {
        $module = $this->recordModule('projects');
        $gone = $this->field($module, 'gone');
        $this->detailsField($module, 'tasks');
        $condition = fn (string $field, string $operator): array => ['all' => [$operator === 'eq' || $operator === 'ne' ? ['field' => $field, 'op' => $operator, 'value' => 'a'] : ['field' => $field, 'op' => $operator]]];
        $written = $this->conditions()->validate($module->id, $condition('gone', 'eq'));
        $recordId = $this->create($module, ['gone' => 'a']);
        $gone->delete();

        $this->assertSame(['all' => [['field' => 'gone', 'op' => 'eq', 'value' => 'a']]], $written);
        $this->assertSame(
            ['eq' => false, 'ne' => false, 'empty' => true, 'not_empty' => false, 'details empty' => true, 'details not_empty' => false],
            [
                'eq' => $this->conditions()->matches($recordId, $written),
                'ne' => $this->conditions()->matches($recordId, $condition('gone', 'ne')),
                'empty' => $this->conditions()->matches($recordId, $condition('gone', 'empty')),
                'not_empty' => $this->conditions()->matches($recordId, $condition('gone', 'not_empty')),
                'details empty' => $this->conditions()->matches($recordId, $condition('tasks', 'empty')),
                'details not_empty' => $this->conditions()->matches($recordId, $condition('tasks', 'not_empty')),
            ],
        );
    }

    /**
     * @param  list<string>  $oldOptions
     * @param  array<string, mixed>  $expression
     * @param  list<string>  $newOptions
     */
    #[DataProvider('recreatedFields')]
    public function test_an_expression_written_for_the_type_before_recreation_is_evaluated_as_empty(RecordFieldType $oldType, array $oldOptions, array $expression, RecordFieldType $newType, array $newOptions, mixed $value, bool $holds): void
    {
        $module = $this->recordModule('projects');
        $old = $oldType === RecordFieldType::Choice ? $this->choiceField($module, 'f', $oldOptions) : $this->field($module, 'f', $oldType);
        $written = $this->conditions()->validate($module->id, ['all' => [['field' => 'f', ...$expression]]]);
        RecordFieldOption::query()->where('record_field_id', $old->id)->delete();
        $old->delete();
        $new = $newType === RecordFieldType::Choice ? $this->choiceField($module, 'f', $newOptions) : $this->field($module, 'f', $newType);
        $record = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$new->id => $value]]);

        $this->assertSame($holds, $this->conditions()->matches($record->id, $written));
    }

    public function test_a_removed_option_is_compared_with_the_copied_value(): void
    {
        $module = $this->recordModule('projects');
        $status = $this->choiceField($module, 'status', ['open', 'done']);
        $eq = $this->conditions()->validate($module->id, ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]]);
        $ne = $this->conditions()->validate($module->id, ['all' => [['field' => 'status', 'op' => 'ne', 'value' => 'done']]]);
        $in = $this->conditions()->validate($module->id, ['all' => [['field' => 'status', 'op' => 'in', 'value' => ['done', 'open']]]]);
        $open = $this->create($module, ['status' => 'open']);
        $done = Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$status->id => 'done']])->id;
        RecordFieldOption::query()->where('record_field_id', $status->id)->where('code', 'done')->delete();

        $this->assertSame(
            [false, true, true, true, false, true],
            [
                $this->conditions()->matches($open, $eq),
                $this->conditions()->matches($open, $ne),
                $this->conditions()->matches($open, $in),
                $this->conditions()->matches($done, $eq),
                $this->conditions()->matches($done, $ne),
                $this->conditions()->matches($done, $in),
            ],
        );
    }

    public function test_validate_returns_the_condition_normalized_as_the_rule_and_both_evaluate_the_same(): void
    {
        $module = $this->recordModule('projects');
        $target = $this->field($module, 'target');
        $this->field($module, 'at', RecordFieldType::Datetime);
        $this->field($module, 'title');
        $input = ['all' => [
            ['field' => ' at ', 'op' => 'ge', 'value' => '2026-10-11T09:00+09:00'],
            ['field' => 'title', 'op' => 'eq', 'value' => '  申請  '],
        ]];

        $written = $this->conditions()->validate($module->id, $input);
        $rule = app(AddRecordRule::class)->execute('projects', ['kind' => 'required_when', 'target' => $target->code, 'condition' => $input]);

        $this->assertSame(['all' => [
            ['field' => 'at', 'op' => 'ge', 'value' => '2026-10-11T00:00:00Z'],
            ['field' => 'title', 'op' => 'eq', 'value' => '申請'],
        ]], $written);
        $this->assertSame($rule->condition, $written);

        foreach (['2026-10-11T08:59+09:00' => false, '2026-10-11T00:00:00Z' => true] as $at => $holds) {
            $values = ['at' => $at, 'title' => ' 申請 '];
            $ruleHolds = $this->createErrors($module, $values) !== [];
            $recordId = $this->create($module, [...$values, 'target' => 'x']);

            $this->assertSame([$holds, $holds], [$ruleHolds, $this->conditions()->matches($recordId, $written)], $at);
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    #[DataProvider('rejectedConditions')]
    public function test_validate_rejects_a_condition_with_the_same_keys_and_messages_as_the_rule(array $condition): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'reason');
        $this->field($module, 'title');
        $this->field($module, 'budget', RecordFieldType::Integer);

        $this->assertSame(
            $this->validationErrors(fn () => app(AddRecordRule::class)->execute('projects', ['kind' => 'required_when', 'target' => 'reason', 'condition' => $condition])),
            $this->validationErrors(fn () => $this->conditions()->validate($module->id, $condition)),
        );
    }

    public function test_a_missing_record_or_a_details_row_is_not_found(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $parent = Record::factory()->create(['record_module_id' => $module->id]);
        $row = Record::factory()->create(['record_module_id' => $tasks->child_record_module_id, 'parent_record_module_id' => $module->id, 'parent_record_id' => $parent->id]);
        $condition = ['all' => [['field' => 'tasks', 'op' => 'empty']]];

        foreach ([(int) Record::query()->max('id') + 1, $row->id] as $recordId) {
            try {
                $this->conditions()->matches($recordId, $condition);
                $this->fail("見つからないにならなかった: {$recordId}");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * {"all": 空でないリスト} の形でない写し。
     *
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function malformedConditions(): array
    {
        return [
            '空のオブジェクト' => [[]],
            'all の無い条件' => [['any' => [['field' => 'title', 'op' => 'empty']]]],
            '空の all' => [['all' => []]],
            '配列でない all' => [['all' => 'x']],
            'リストでない all' => [['all' => ['a' => ['field' => 'title', 'op' => 'empty']]]],
            'all のほかのキーを持つ条件' => [['all' => [['field' => 'title', 'op' => 'empty']], 'any' => []]],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $condition
     */
    #[DataProvider('malformedConditions')]
    public function test_a_condition_not_in_the_form_of_all_with_expressions_does_not_hold(array $condition): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');

        $this->assertFalse($this->conditions()->matches($this->create($module, []), $condition));
    }

    public function test_validate_does_not_find_a_details_module_or_a_missing_module(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($this->childOf($tasks), 'hours', RecordFieldType::Integer);
        $condition = ['all' => [['field' => 'hours', 'op' => 'empty']]];

        foreach ([(int) $tasks->child_record_module_id, (int) RecordModule::query()->max('id') + 1] as $moduleId) {
            try {
                $this->conditions()->validate($moduleId, $condition);
                $this->fail("見つからないにならなかった: {$moduleId}");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function conditions(): RecordConditions
    {
        return app(RecordConditions::class);
    }

    /**
     * ゲストとしてレコードを登録し、その id を返す。
     *
     * @param  array<string, mixed>  $values
     */
    private function create(RecordModule $module, array $values): int
    {
        return app(CreateRecord::class)->execute($this->contextFor(), $module->code, new RecordInput($values, []));
    }

    /**
     * ゲストとして登録したときの検証の失敗(通れば空。登録したレコードは残す)。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, list<string>>
     */
    private function createErrors(RecordModule $module, array $values): array
    {
        try {
            $this->create($module, $values);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    }
}

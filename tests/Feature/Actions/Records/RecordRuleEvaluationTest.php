<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 必須・条件つき必須・相関チェックの評価(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8 節・9.2 の 7・8、失敗と定義の変更.md 10 節の文言、
 * 画面と検査.md 13 節の「必須・条件つき必須・相関チェック」= 演算子 × 型の表の各組で真と偽、空のときの意味、複数の失敗をまとめて返す、明細の行の規則、明細の必須)。
 */
final class RecordRuleEvaluationTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /**
     * 条件の式 1 つ(項目 f)と f の入力 → 条件が真か(真なら対象 target が必須になる)。画面の評価と共有する表(入力 = 画面が送る値)と、
     * 画面が送らない形の入力の行(規則と業務処理/画面と検査.md 12 節)。
     *
     * @return array<string, array{RecordFieldType, string, mixed, mixed, bool}>
     */
    public static function expressions(): array
    {
        $json = (string) file_get_contents(__DIR__.'/record-condition-expressions.json');
        /**
         * @var array<string, array{type: string, isMultiple?: bool, op: string, value: mixed, server: mixed,
         *     holds: bool}> $shared
         */
        $shared = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        // 複数選べる参照の行は相手のユーザーを作ってから入力にするので、MultipleReferenceRuleEvaluationTest が読む
        $single = array_filter($shared, fn (array $row): bool => ! ($row['isMultiple'] ?? false));

        return [
            ...array_map(fn (array $row): array => [RecordFieldType::from($row['type']), $row['op'], $row['value'], $row['server'], $row['holds']], $single),
            '整数 eq 偽(数の入力)' => [RecordFieldType::Integer, 'eq', 10, 11, false],
            '整数 lt 真(数の入力)' => [RecordFieldType::Integer, 'lt', 10, 9, true],
            '整数 ne 真(数の入力)' => [RecordFieldType::Integer, 'ne', 10, 11, true],
            '整数 ne 偽(数の入力)' => [RecordFieldType::Integer, 'ne', 10, 10, false],
            '整数 le 真(数の入力)' => [RecordFieldType::Integer, 'le', 10, 10, true],
            '整数 le 偽(数の入力)' => [RecordFieldType::Integer, 'le', 10, 11, false],
            '整数 ge 真(数の入力)' => [RecordFieldType::Integer, 'ge', 10, 10, true],
            '整数 ge 偽(数の入力)' => [RecordFieldType::Integer, 'ge', 10, 9, false],
            '整数 gt 真(数の入力)' => [RecordFieldType::Integer, 'gt', 10, 11, true],
            '整数 gt 偽(数の入力)' => [RecordFieldType::Integer, 'gt', 10, 10, false],
            '日時 gt 真(秒の無い入力)' => [RecordFieldType::Datetime, 'gt', '2026-10-11T00:00:00Z', '2026-10-11T09:30+09:00', true],
            '真偽 eq false はキーが無いとき真' => [RecordFieldType::Boolean, 'eq', false, null, true],
        ];
    }

    /**
     * 相関チェック(対象 a・相手 b の値)→ 通るか。
     *
     * @return array<string, array{RecordFieldType, RecordComparisonOperator, mixed, mixed, bool}>
     */
    public static function comparisons(): array
    {
        return [
            '整数 lt 通る' => [RecordFieldType::Integer, RecordComparisonOperator::Lt, 1, 2, true],
            '整数 lt 失敗' => [RecordFieldType::Integer, RecordComparisonOperator::Lt, 2, 2, false],
            '整数 le 通る' => [RecordFieldType::Integer, RecordComparisonOperator::Le, 2, 2, true],
            '整数 le 失敗' => [RecordFieldType::Integer, RecordComparisonOperator::Le, 3, 2, false],
            '整数 eq 失敗' => [RecordFieldType::Integer, RecordComparisonOperator::Eq, 3, 2, false],
            '整数 ne 失敗' => [RecordFieldType::Integer, RecordComparisonOperator::Ne, 2, 2, false],
            '整数 ge 失敗' => [RecordFieldType::Integer, RecordComparisonOperator::Ge, 1, 2, false],
            '整数 gt 失敗' => [RecordFieldType::Integer, RecordComparisonOperator::Gt, 2, 2, false],
            '小数 gt 通る' => [RecordFieldType::Decimal, RecordComparisonOperator::Gt, '0.1', '0.09', true],
            '小数 ge 失敗' => [RecordFieldType::Decimal, RecordComparisonOperator::Ge, '99.9999', '100', false],
            '日付 ge 通る' => [RecordFieldType::Date, RecordComparisonOperator::Ge, '2026-10-11', '2026-10-01', true],
            '日付 ge 失敗' => [RecordFieldType::Date, RecordComparisonOperator::Ge, '2026-09-30', '2026-10-01', false],
            '日時 lt 通る' => [RecordFieldType::Datetime, RecordComparisonOperator::Lt, '2026-10-11T08:00+09:00', '2026-10-11T00:00Z', true],
            '日時 lt 失敗' => [RecordFieldType::Datetime, RecordComparisonOperator::Lt, '2026-10-11T09:00+09:00', '2026-10-11T00:00Z', false],
            '相手が空なら評価しない' => [RecordFieldType::Integer, RecordComparisonOperator::Lt, 5, null, true],
            '対象が空なら評価しない' => [RecordFieldType::Integer, RecordComparisonOperator::Lt, null, 5, true],
        ];
    }

    #[DataProvider('expressions')]
    public function test_a_condition_expression_makes_the_target_required_only_when_it_holds(RecordFieldType $type, string $operator, mixed $expected, mixed $input, bool $holds): void
    {
        $module = $this->recordModule('projects');
        $target = $this->field($module, 'target');

        if ($type === RecordFieldType::Choice) {
            $this->choiceField($module, 'f', ['open', 'done', 'hold']);
        } else {
            $this->field($module, 'f', $type);
        }

        $expression = in_array($operator, ['empty', 'not_empty'], true) ? ['field' => 'f', 'op' => $operator] : ['field' => 'f', 'op' => $operator, 'value' => $expected];
        $this->requiredWhen($module, $target, ['all' => [$expression]]);

        $errors = $this->errorsOf($module, $input === null ? [] : ['f' => $input]);

        $this->assertSame($holds ? ['values.target' => 'targetを入力してください'] : [], $errors);
    }

    public function test_references_can_be_tested_for_emptiness(): void
    {
        $module = $this->recordModule('projects');
        $target = $this->field($module, 'target');
        $this->field($module, 'owner', RecordFieldType::User);
        $this->requiredWhen($module, $target, ['all' => [['field' => 'owner', 'op' => 'not_empty']]]);

        $this->assertSame([], $this->errorsOf($module, []));
        $this->assertSame(['values.target' => 'targetを入力してください'], $this->errorsOf($module, ['owner' => $this->user()->id]));
    }

    public function test_all_expressions_must_hold(): void
    {
        $module = $this->recordModule('projects');
        $target = $this->field($module, 'target');
        $this->choiceField($module, 'status', ['open', 'done']);
        $this->field($module, 'budget', RecordFieldType::Integer);
        $this->requiredWhen($module, $target, ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done'], ['field' => 'budget', 'op' => 'ge', 'value' => 100]]]);

        $this->assertSame([], $this->errorsOf($module, ['status' => 'done', 'budget' => 99]));
        $this->assertSame(['values.target' => 'targetを入力してください'], $this->errorsOf($module, ['status' => 'done', 'budget' => 100]));
    }

    #[DataProvider('comparisons')]
    public function test_a_comparison_fails_only_when_both_values_exist_and_it_does_not_hold(RecordFieldType $type, RecordComparisonOperator $operator, mixed $left, mixed $right, bool $passes): void
    {
        $module = $this->recordModule('projects');
        $a = $this->field($module, 'a', $type);
        $b = $this->field($module, 'b', $type);
        RecordRule::factory()->create(['record_module_id' => $module->id, 'kind' => RecordRuleKind::Compare, 'target_record_field_id' => $a->id, 'condition' => null, 'operator' => $operator, 'compared_record_field_id' => $b->id]);

        $errors = $this->errorsOf($module, array_filter(['a' => $left, 'b' => $right], fn (mixed $value) => $value !== null));

        $words = ['lt' => 'より小さい値', 'le' => '以下', 'eq' => 'と同じ値', 'ne' => 'と違う値', 'ge' => '以上', 'gt' => 'より大きい値'];
        $this->assertSame($passes ? [] : ['values.a' => "aはb{$words[$operator->value]}にしてください"], $errors);
    }

    public function test_uses_the_message_of_the_rule_and_returns_every_failure_at_once(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title', attributes: ['is_required' => true]);
        $start = $this->field($module, 'start', RecordFieldType::Date);
        $end = $this->field($module, 'end', RecordFieldType::Date);
        $reason = $this->field($module, 'reason');
        RecordRule::factory()->create(['record_module_id' => $module->id, 'kind' => RecordRuleKind::Compare, 'target_record_field_id' => $end->id, 'condition' => null, 'operator' => RecordComparisonOperator::Ge, 'compared_record_field_id' => $start->id, 'message' => '終了日は開始日以降にしてください']);
        $this->requiredWhen($module, $reason, ['all' => [['field' => 'end', 'op' => 'not_empty']]]);

        $errors = $this->errorsOf($module, ['start' => '2026-10-11', 'end' => '2026-10-01']);

        $this->assertSame([
            'values.title' => 'titleを入力してください',
            'values.end' => '終了日は開始日以降にしてください',
            'values.reason' => 'reasonを入力してください',
        ], $errors);
    }

    public function test_evaluates_the_rules_of_the_details_module_for_each_row_and_requires_rows(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks', ['is_required' => true]);
        $child = $this->childOf($tasks);
        $this->field($child, 'hours', RecordFieldType::Integer);
        $note = $this->field($child, 'note');
        $this->requiredWhen($child, $note, ['all' => [['field' => 'hours', 'op' => 'gt', 'value' => 8]]]);

        $this->assertSame(['details.tasks' => 'tasksを 1 行以上入力してください'], $this->errorsOf($module, []));
        $this->assertSame(
            ['details.tasks.1.values.note' => 'noteを入力してください'],
            $this->errorsOf($module, [], ['tasks' => [new RecordDetailRowInput(values: ['hours' => 8]), new RecordDetailRowInput(values: ['hours' => 9])]]),
        );
    }

    public function test_a_details_field_as_the_target_of_a_conditional_requirement_needs_a_row(): void
    {
        $module = $this->recordModule('projects');
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($module, 'planned', RecordFieldType::Boolean);
        $this->requiredWhen($module, $tasks, ['all' => [['field' => 'planned', 'op' => 'eq', 'value' => true]]]);

        $this->assertSame(['details.tasks' => 'tasksを 1 行以上入力してください'], $this->errorsOf($module, ['planned' => true]));
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private function requiredWhen(RecordModule $module, RecordField $target, array $condition): void
    {
        RecordRule::factory()->create(['record_module_id' => $module->id, 'kind' => RecordRuleKind::RequiredWhen, 'target_record_field_id' => $target->id, 'condition' => $condition]);
    }

    /**
     * ゲストとして登録したときの検証の失敗(通れば空)。
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<RecordDetailRowInput>>  $details
     * @return array<string, string>
     */
    private function errorsOf(RecordModule $module, array $values, array $details = []): array
    {
        try {
            app(CreateRecord::class)->execute($this->contextFor(), $module->code, new RecordInput($values, $details));
        } catch (ValidationException $exception) {
            return array_map(fn (array $messages): string => (string) $messages[0], $exception->errors());
        }

        return [];
    }
}

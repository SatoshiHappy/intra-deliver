<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordRule;
use App\Actions\Records\DeleteRecordRule;
use App\Actions\Records\UpdateRecordRule;
use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 規則を作る・変える・消す業務処理(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8 節、失敗と定義の変更.md 11 節、画面と検査.md 13 節の
 * 「条件の `eq` の `value` が空なら 422」と型ごとの可否)。
 */
final class RecordRuleDefinitionTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    /**
     * 規則の入力 → 拒むキーと文言。
     *
     * @return array<string, array{array<string, mixed>, array<string, string>}>
     */
    public static function rejectedRules(): array
    {
        $when = fn (array $expression): array => ['kind' => 'required_when', 'target' => 'reason', 'condition' => ['all' => [$expression]]];

        return [
            '種別が無い' => [['kind' => 'unique', 'target' => 'reason'], ['kind' => '種別を選択してください']],
            '対象が無い' => [['kind' => 'required_when', 'target' => 'nothing', 'condition' => ['all' => [['field' => 'title', 'op' => 'empty']]]], ['target' => '対象の項目を選択してください']],
            '真偽を対象にした条件つき必須' => [['kind' => 'required_when', 'target' => 'urgent', 'condition' => ['all' => [['field' => 'title', 'op' => 'empty']]]], ['target' => '真偽の項目は条件つき必須の対象にできません']],
            'eq の value が空' => [$when(['field' => 'title', 'op' => 'eq', 'value' => '  ']), ['condition.all.0.value' => '値を入力してください']],
            '真偽の eq の value が無い' => [$when(['field' => 'urgent', 'op' => 'eq']), ['condition.all.0.value' => '値を入力してください']],
            'in の空の配列' => [$when(['field' => 'status', 'op' => 'in', 'value' => []]), ['condition.all.0.value' => '値を入力してください']],
            'in に選択肢に無いコード' => [$when(['field' => 'status', 'op' => 'in', 'value' => ['open', 'closed']]), ['condition.all.0.value' => 'statusの値が正しくありません']],
            'empty に value' => [$when(['field' => 'title', 'op' => 'empty', 'value' => 'a']), ['condition.all.0.value' => 'この演算子には値を設定できません']],
            '文字列に lt' => [$when(['field' => 'title', 'op' => 'lt', 'value' => 'a']), ['condition.all.0.op' => 'この項目で使用できる演算子を選択してください']],
            'ユーザーに eq' => [$when(['field' => 'owner', 'op' => 'eq', 'value' => 1]), ['condition.all.0.op' => 'この項目で使用できる演算子を選択してください']],
            '整数の型が違う value' => [$when(['field' => 'budget', 'op' => 'ge', 'value' => '1.5']), ['condition.all.0.value' => 'budgetは整数で入力してください']],
            '明細の項目' => [$when(['field' => 'tasks', 'op' => 'empty']), ['condition.all.0.field' => '条件の項目を選択してください(明細の項目は使用できません)']],
            '式のほかのキー' => [$when(['field' => 'title', 'op' => 'empty', 'note' => 'x']), ['condition.all.0' => '式に指定できるのは field・op・value だけです']],
            '式が 11 個' => [['kind' => 'required_when', 'target' => 'reason', 'condition' => ['all' => array_fill(0, 11, ['field' => 'title', 'op' => 'empty'])]], ['condition' => '条件は {"all": [式, …]} の形で、式を 1〜10 個指定してください']],
            'all の無い条件' => [['kind' => 'required_when', 'target' => 'reason', 'condition' => ['any' => []]], ['condition' => '条件は {"all": [式, …]} の形で、式を 1〜10 個指定してください']],
            '条件つき必須に比べ方' => [[...$when(['field' => 'title', 'op' => 'empty']), 'operator' => 'lt'], ['operator' => '条件つき必須には比べ方を設定できません']],
            '文字列の相関チェック' => [['kind' => 'compare', 'target' => 'title', 'operator' => 'eq', 'compared' => 'reason'], ['target' => '相関チェックの対象は整数・小数・日付・日時の項目にしてください']],
            '型の違う相手' => [['kind' => 'compare', 'target' => 'budget', 'operator' => 'le', 'compared' => 'start'], ['compared' => '相手の項目は対象と同じ型の項目にしてください']],
            '同じ項目を相手に' => [['kind' => 'compare', 'target' => 'start', 'operator' => 'le', 'compared' => 'start'], ['compared' => '相手の項目は対象と別の項目にしてください']],
            '比べ方が無い' => [['kind' => 'compare', 'target' => 'start', 'operator' => 'between', 'compared' => 'end'], ['operator' => '比べ方を選択してください']],
            '相関チェックに条件' => [['kind' => 'compare', 'target' => 'start', 'operator' => 'le', 'compared' => 'end', 'condition' => ['all' => []]], ['condition' => '相関チェックには条件を設定できません']],
            '文言の U+0000' => [['kind' => 'compare', 'target' => 'start', 'operator' => 'le', 'compared' => 'end', 'message' => "a\0b"], ['message' => '文言に使用できない文字(U+0000)が含まれています']],
        ];
    }

    public function test_creates_a_conditional_requirement_with_normalized_values(): void
    {
        $this->projects();

        $rule = $this->add(['kind' => 'required_when', 'target' => 'reason', 'condition' => ['all' => [
            ['field' => 'rate', 'op' => 'ge', 'value' => '1.50'],
            ['field' => 'starts_at', 'op' => 'lt', 'value' => '2026-10-11T09:00+09:00'],
            ['field' => 'status', 'op' => 'in', 'value' => [' open ', 'done']],
            ['field' => 'owner', 'op' => 'not_empty'],
        ]], 'message' => '  ']);

        $this->assertSame(RecordRuleKind::RequiredWhen, $rule->refresh()->kind);
        $this->assertEquals(['all' => [
            ['field' => 'rate', 'op' => 'ge', 'value' => '1.5'],
            ['field' => 'starts_at', 'op' => 'lt', 'value' => '2026-10-11T00:00:00Z'],
            ['field' => 'status', 'op' => 'in', 'value' => ['open', 'done']],
            ['field' => 'owner', 'op' => 'not_empty'],
        ]], $rule->condition);
        $this->assertNull($rule->message);
    }

    public function test_creates_a_comparison(): void
    {
        $module = $this->projects();

        $rule = $this->add(['kind' => 'compare', 'target' => 'end', 'operator' => 'ge', 'compared' => 'start', 'message' => '終了日は開始日以降']);

        $fields = $module->recordFields()->pluck('id', 'code');
        $this->assertSame([RecordRuleKind::Compare, RecordComparisonOperator::Ge, $fields['end'], $fields['start'], null, '終了日は開始日以降'], [$rule->kind, $rule->operator, $rule->target_record_field_id, $rule->compared_record_field_id, $rule->condition, $rule->message]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $expected
     */
    #[DataProvider('rejectedRules')]
    public function test_rejects_a_rule_that_breaks_the_rules_and_writes_nothing(array $input, array $expected): void
    {
        $module = $this->projects();

        $this->assertSame($expected, $this->validationErrors(fn () => $this->add($input)));
        $this->assertSame(0, RecordRule::query()->where('record_module_id', $module->id)->count());
    }

    public function test_changes_a_rule_but_not_its_kind_and_deletes_it(): void
    {
        $this->projects();
        $rule = $this->add(['kind' => 'compare', 'target' => 'end', 'operator' => 'ge', 'compared' => 'start']);

        $kindChange = $this->validationErrors(fn () => app(UpdateRecordRule::class)->execute('projects', $rule->id, ['kind' => 'required_when', 'target' => 'reason', 'condition' => ['all' => [['field' => 'title', 'op' => 'empty']]]]));
        $changed = app(UpdateRecordRule::class)->execute('projects', $rule->id, ['target' => 'end', 'operator' => 'gt', 'compared' => 'start', 'message' => '後にしてください']);
        app(DeleteRecordRule::class)->execute('projects', $rule->id);

        $this->assertSame(['kind' => '種別は変更できません(削除してから再度追加してください)'], $kindChange);
        $this->assertSame([RecordComparisonOperator::Gt, '後にしてください'], [$changed->operator, $changed->message]);
        $this->assertModelMissing($rule);
    }

    public function test_a_rule_of_another_module_is_not_found(): void
    {
        $this->projects();
        $other = RecordRule::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(DeleteRecordRule::class)->execute('projects', $other->id);
    }

    /**
     * 業務モジュール projects と、規則に使う項目。
     */
    private function projects(): RecordModule
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');
        $this->field($module, 'reason');
        $this->field($module, 'urgent', RecordFieldType::Boolean);
        $this->field($module, 'budget', RecordFieldType::Integer);
        $this->field($module, 'rate', RecordFieldType::Decimal);
        $this->field($module, 'start', RecordFieldType::Date);
        $this->field($module, 'end', RecordFieldType::Date);
        $this->field($module, 'starts_at', RecordFieldType::Datetime);
        $this->field($module, 'owner', RecordFieldType::User);
        $this->choiceField($module, 'status', ['open', 'done']);
        $this->detailsField($module, 'tasks');

        return $module;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function add(array $input): RecordRule
    {
        return app(AddRecordRule::class)->execute('projects', $input);
    }
}

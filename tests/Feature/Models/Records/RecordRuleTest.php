<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordField;
use App\Models\Records\RecordRule;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 規則(docs の 設計/業務データの入れ物/データモデル/表.md 2.4・制約の分担と検査.md 7 節: 同じ業務モジュールの項目だけを指す、種別ごとに持つもの、条件は JSON のオブジェクト、
 * 対象と相手は別の項目、文言は空文字にしない(NULL = 既定の文言))。
 */
final class RecordRuleTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const KIND_CHECK = 'record_rules_kind_condition_operator_compared_record_field_id_check';

    /**
     * 種別と持つものが合わない規則。
     *
     * @return array<string, array{string|null, array<string, mixed>}>
     */
    public static function mismatchedKinds(): array
    {
        return [
            '条件の無い条件つき必須' => [null, ['condition' => null]],
            '比べ方を持つ条件つき必須' => [null, ['operator' => RecordComparisonOperator::Eq]],
            '条件を持つ相関チェック' => ['compare', ['condition' => ['all' => [['field' => 'status', 'op' => 'empty']]]]],
            '比べ方の無い相関チェック' => ['compare', ['operator' => null]],
            '相手の無い相関チェック' => ['compare', ['compared_record_field_id' => null]],
        ];
    }

    public function test_reads_the_kind_the_condition_and_the_operator(): void
    {
        $requiredWhen = RecordRule::factory()->create()->refresh();
        $compare = RecordRule::factory()->compare()->create()->refresh();

        $this->assertSame(RecordRuleKind::RequiredWhen, $requiredWhen->kind);
        $this->assertEquals(['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]], $requiredWhen->condition, 'jsonb はキーを並べ替えるので順を問わない');
        $this->assertNull($requiredWhen->operator);
        $this->assertSame(RecordRuleKind::Compare, $compare->kind);
        $this->assertSame(RecordComparisonOperator::Le, $compare->operator);
        $this->assertNull($compare->condition);
    }

    public function test_rejects_a_kind_or_an_operator_outside_the_list(): void
    {
        $requiredWhen = RecordRule::factory()->makeOne()->getAttributes();
        $compare = RecordRule::factory()->compare()->makeOne()->getAttributes();

        $this->assertRejectedBy('record_rules_kind_check', fn () => DB::table('record_rules')->insert([...$requiredWhen, 'kind' => 'unique']));
        $this->assertRejectedBy('record_rules_operator_check', fn () => DB::table('record_rules')->insert([...$compare, 'operator' => 'between']));
    }

    public function test_rejects_a_target_field_of_another_module(): void
    {
        $this->assertRejectedBy(
            'record_rules_record_module_id_target_record_field_id_foreign',
            fn () => RecordRule::factory()->create(['target_record_field_id' => RecordField::factory()]),
        );
    }

    public function test_rejects_a_compared_field_of_another_module(): void
    {
        $this->assertRejectedBy(
            'record_rules_record_module_id_compared_record_field_id_foreign',
            fn () => RecordRule::factory()->compare()->create(['compared_record_field_id' => RecordField::factory()->state(['type' => RecordFieldType::Integer])]),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('mismatchedKinds')]
    public function test_rejects_a_rule_whose_parts_do_not_match_its_kind(?string $state, array $attributes): void
    {
        $factory = $state === null ? RecordRule::factory() : RecordRule::factory()->{$state}();

        $this->assertRejectedBy(self::KIND_CHECK, fn () => $factory->create($attributes));
    }

    public function test_rejects_a_condition_that_is_not_a_json_object(): void
    {
        $this->assertRejectedBy('record_rules_condition_check', fn () => RecordRule::factory()->create(['condition' => [['field' => 'status', 'op' => 'empty']]]));
    }

    public function test_rejects_comparing_a_field_with_itself(): void
    {
        $rule = RecordRule::factory()->compare()->create();

        $this->assertRejectedBy(
            'record_rules_target_record_field_id_compared_record_field_id_check',
            fn () => $rule->update(['compared_record_field_id' => $rule->target_record_field_id]),
        );
    }

    public function test_rejects_an_empty_message_and_accepts_a_message(): void
    {
        $this->assertRejectedBy('record_rules_message_check', fn () => RecordRule::factory()->create(['message' => '']));
        $this->assertSame('完了なら必須です', RecordRule::factory()->create(['message' => '完了なら必須です'])->refresh()->message);
    }
}

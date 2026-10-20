<?php

namespace Database\Factories\Records;

use App\Enums\Records\RecordComparisonOperator;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRuleKind;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordRule>
 */
class RecordRuleFactory extends Factory
{
    /**
     * Define the model's default state(条件つき必須。対象の項目を同じ業務モジュールに作る).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_module_id' => RecordModule::factory(),
            'kind' => RecordRuleKind::RequiredWhen,
            'target_record_field_id' => fn (array $attributes) => RecordField::factory()->state(['record_module_id' => $attributes['record_module_id']]),
            'condition' => ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'done']]],
            'operator' => null,
            'compared_record_field_id' => null,
            'message' => null,
        ];
    }

    /**
     * 相関チェックにする(対象と相手の整数の項目を同じ業務モジュールに作る)。
     */
    public function compare(): static
    {
        return $this->state(fn (): array => [
            'kind' => RecordRuleKind::Compare,
            'target_record_field_id' => fn (array $attributes) => RecordField::factory()->state(['record_module_id' => $attributes['record_module_id'], 'type' => RecordFieldType::Integer]),
            'condition' => null,
            'operator' => RecordComparisonOperator::Le,
            'compared_record_field_id' => fn (array $attributes) => RecordField::factory()->state(['record_module_id' => $attributes['record_module_id'], 'type' => RecordFieldType::Integer]),
        ]);
    }
}

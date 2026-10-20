<?php

namespace Database\Factories\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordField>
 */
class RecordFieldFactory extends Factory
{
    /**
     * Define the model's default state(1 行の文字列の項目).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_module_id' => RecordModule::factory(),
            'code' => fake()->unique()->bothify('field_????####'),
            'name' => fake()->words(2, true),
            'type' => RecordFieldType::String,
            'is_required' => false,
            'is_multiline' => false,
            'max_length' => null,
            'default_value' => null,
            'referenced_record_module_id' => null,
            'child_record_module_id' => null,
            'is_listed' => false,
            'is_searchable' => false,
            'is_title' => false,
            'sort_key' => 0,
        ];
    }

    /**
     * 他モジュール参照の項目にする(参照先の業務モジュールを作る)。
     */
    public function reference(): static
    {
        return $this->state(fn (): array => ['type' => RecordFieldType::Record, 'referenced_record_module_id' => RecordModule::factory()]);
    }

    /**
     * 明細の項目にする(この項目の業務モジュールを親にした明細のモジュールを作る)。
     */
    public function details(): static
    {
        return $this->state(fn (): array => [
            'type' => RecordFieldType::Details,
            'child_record_module_id' => fn (array $attributes) => RecordModule::factory()->state(['parent_record_module_id' => $attributes['record_module_id']]),
        ]);
    }
}

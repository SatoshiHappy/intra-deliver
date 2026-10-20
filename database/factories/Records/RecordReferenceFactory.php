<?php

namespace Database\Factories\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordReference>
 */
class RecordReferenceFactory extends Factory
{
    /**
     * Define the model's default state(ユーザー参照の項目の値。持ち主のレコードと項目を同じ業務モジュールに作る).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_module_id' => RecordModule::factory(),
            'record_id' => fn (array $attributes) => Record::factory()->state(['record_module_id' => $attributes['record_module_id']]),
            'record_field_id' => fn (array $attributes) => RecordField::factory()->state(['record_module_id' => $attributes['record_module_id'], 'type' => RecordFieldType::User]),
            'referenced_record_module_id' => null,
            'referenced_record_id' => null,
            'user_id' => User::factory(),
            'department_id' => null,
        ];
    }

    /**
     * 組織参照の項目の値にする。
     */
    public function forDepartment(): static
    {
        return $this->state(fn (): array => [
            'record_field_id' => fn (array $attributes) => RecordField::factory()->state(['record_module_id' => $attributes['record_module_id'], 'type' => RecordFieldType::Department]),
            'user_id' => null,
            'department_id' => Department::factory(),
        ]);
    }

    /**
     * 他モジュール参照の項目の値にする(参照先の業務モジュールのレコードを作る)。
     */
    public function forRecord(): static
    {
        return $this->state(fn (): array => [
            'record_field_id' => fn (array $attributes) => RecordField::factory()->reference()->state(['record_module_id' => $attributes['record_module_id']]),
            'referenced_record_module_id' => fn (array $attributes) => RecordField::query()->whereKey($attributes['record_field_id'])->sole()->referenced_record_module_id,
            'referenced_record_id' => fn (array $attributes) => Record::factory()->state(['record_module_id' => $attributes['referenced_record_module_id']]),
            'user_id' => null,
        ]);
    }
}

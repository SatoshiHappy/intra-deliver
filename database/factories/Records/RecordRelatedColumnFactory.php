<?php

namespace Database\Factories\Records;

use App\Models\Records\RecordField;
use App\Models\Records\RecordRelatedColumn;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は、他モジュール参照の項目を入口にし、表の行になる業務モジュールの項目を列にした行(docs の
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.7 節)。
 *
 * @extends Factory<RecordRelatedColumn>
 */
class RecordRelatedColumnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_field_id' => RecordField::factory()->reference(),
            'record_module_id' => function (array $attributes): int {
                $entry = RecordField::query()->whereKey($attributes['record_field_id'])->sole();
                $module = $entry->recordModule()->sole();

                return $module->parent_record_module_id ?? $module->id;
            },
            'column_record_field_id' => fn (array $attributes) => RecordField::factory()
                ->state(['record_module_id' => $attributes['record_module_id']]),
            'sort_key' => 0,
        ];
    }
}

<?php

namespace Database\Factories\Records;

use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordModule>
 */
class RecordModuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('module_????####'),
            'name' => fake()->words(2, true),
            'description' => null,
            // 親の無い業務モジュールだけがアイコンを持つ(検査制約 record_modules_parent_record_module_id_icon_check)。親は上書き・detail() の後の値で決める
            'icon' => fn (array $attributes) => $attributes['parent_record_module_id'] === null ? 'table' : null,
            // 明細のモジュールは添付を使用しない(検査制約 record_modules_parent_record_module_id_is_attachment_enabled_check)。
            // icon と同じく親から決める
            'is_attachment_enabled' => fn (array $attributes) => $attributes['parent_record_module_id'] === null,
            'parent_record_module_id' => null,
            'sort_key' => 0,
        ];
    }

    /**
     * 明細のモジュールにする(親の業務モジュールを作る)。
     */
    public function detail(): static
    {
        return $this->state(fn (): array => ['parent_record_module_id' => RecordModule::factory()]);
    }
}

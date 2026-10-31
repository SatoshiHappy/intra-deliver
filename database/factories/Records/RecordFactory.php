<?php

namespace Database\Factories\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Record>
 */
class RecordFactory extends Factory
{
    /**
     * Define the model's default state(親の無い業務モジュールのレコード。値なし、ゲストが作った).
     * チェック「参照を制限する」は登録と同じく業務モジュールの参照制限の既定(明細の行は偽 = 検査制約
     * records_parent_record_id_row_access_restricted_check)。業務モジュールと親が決まった後に読む。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_module_id' => RecordModule::factory(),
            'parent_record_module_id' => null,
            'parent_record_id' => null,
            'sort_key' => 0,
            'field_values' => [],
            'created_by_user_id' => null,
            'updated_by_user_id' => null,
            'row_access_restricted' => fn (array $attributes) => $attributes['parent_record_id'] === null
                && RecordModule::query()->whereKey($attributes['record_module_id'])
                    ->value('row_access_restricted_by_default') === true,
        ];
    }

    /**
     * 明細の行にする(明細のモジュールと、その親の業務モジュールのレコードを親として作る)。
     */
    public function detail(): static
    {
        return $this->state(fn (): array => [
            'record_module_id' => RecordModule::factory()->detail(),
            'parent_record_module_id' => fn (array $attributes) => RecordModule::query()->whereKey($attributes['record_module_id'])->sole()->parent_record_module_id,
            'parent_record_id' => fn (array $attributes) => Record::factory()->state(['record_module_id' => $attributes['parent_record_module_id']]),
            'sort_key' => 1,
        ]);
    }

    /**
     * 作成者と更新者を、別々のユーザーにする。
     */
    public function byUsers(): static
    {
        return $this->state(fn (): array => ['created_by_user_id' => User::factory(), 'updated_by_user_id' => User::factory()]);
    }
}

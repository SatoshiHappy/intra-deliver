<?php

namespace Database\Factories\Records;

use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordRowAccessViewer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordRowAccessViewer>
 */
class RecordRowAccessViewerFactory extends Factory
{
    /**
     * Define the model's default state(参照を制限したレコードの参照者のユーザー。レコードは業務モジュールの既定で作るので、チェックを付けて作る).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_id' => Record::factory()->state(['row_access_restricted' => true]),
            'user_id' => User::factory(),
            'department_id' => null,
            'sort_key' => 0,
        ];
    }

    /**
     * 参照者の組織にする。
     */
    public function forDepartment(): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'department_id' => Department::factory()]);
    }
}

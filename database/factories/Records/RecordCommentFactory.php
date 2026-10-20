<?php

namespace Database\Factories\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は削除していないコメント(書いた人を作る)。
 *
 * @extends Factory<RecordComment>
 */
class RecordCommentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_module_id' => RecordModule::factory(),
            'record_id' => fn (array $attributes) => Record::factory()
                ->state(['record_module_id' => $attributes['record_module_id']]),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
            'deleted_at' => null,
            'deleted_by_user_id' => null,
        ];
    }

    /**
     * 削除したコメントにする(本文を消し、削除した日時と人を持つ)。
     */
    public function deleted(): static
    {
        return $this->state(fn (): array => [
            'body' => null,
            'deleted_at' => now(),
            'deleted_by_user_id' => User::factory(),
        ]);
    }
}

<?php

namespace Database\Factories\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Upload\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定はゲストが付けた添付(レコードと実体を作る)。
 *
 * @extends Factory<RecordAttachment>
 */
class RecordAttachmentFactory extends Factory
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
            'upload_id' => Upload::factory(),
            'name' => fake()->word().'.pdf',
            'user_id' => null,
        ];
    }

    /**
     * 付けた人のいる添付にする。
     */
    public function byUser(): static
    {
        return $this->state(fn (): array => ['user_id' => User::factory()]);
    }
}

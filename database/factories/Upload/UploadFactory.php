<?php

namespace Database\Factories\Upload;

use App\Models\Master\User;
use App\Models\Upload\Upload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * 既定はゲストが上げた、実体のある行(保存の名前は乱数)。
 *
 * @extends Factory<Upload>
 */
class UploadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'path' => Str::random(40).'.pdf',
            'size' => fake()->numberBetween(1, 1_000_000),
            'mime' => 'application/pdf',
            'user_id' => null,
            'purged_at' => null,
            'purge_due_on' => null,
        ];
    }

    /**
     * 上げた人のいる行。
     */
    public function byUser(): static
    {
        return $this->state(fn (): array => ['user_id' => User::factory()]);
    }

    /**
     * 期限の削除で実体を消した行(消した日時と期限の日を持つ)。
     */
    public function purged(): static
    {
        return $this->state(fn (): array => ['purged_at' => now(), 'purge_due_on' => today()]);
    }
}

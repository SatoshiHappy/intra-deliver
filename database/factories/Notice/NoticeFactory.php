<?php

namespace Database\Factories\Notice;

use App\Models\Master\User;
use App\Models\Notice\Notice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は対象と起こした人の無い未読の通知(値は空のオブジェクト)。
 *
 * @extends Factory<Notice>
 */
class NoticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'test.notice',
            'target_type' => null,
            'target_id' => null,
            'actor_user_id' => null,
            'params' => (object) [],
            'read_at' => null,
        ];
    }

    /**
     * 起こした人のある通知。
     */
    public function withActor(): static
    {
        return $this->state(['actor_user_id' => User::factory()]);
    }

    /**
     * 対象のある通知(対象の行は作らない = 外部キーの無い参照)。
     */
    public function withTarget(string $targetType = 'test-target', int $targetId = 1): static
    {
        return $this->state(['target_type' => $targetType, 'target_id' => $targetId]);
    }
}

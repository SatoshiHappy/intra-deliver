<?php

namespace Database\Factories\Notice;

use App\Models\Master\User;
use App\Models\Notice\NoticeOptOut;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は新しい利用者がテストの種類を受け取らない印。
 *
 * @extends Factory<NoticeOptOut>
 */
class NoticeOptOutFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'test.notice',
        ];
    }
}

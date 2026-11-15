<?php

namespace Database\Factories\Notice;

use App\Models\Notice\NoticeRequiredType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定はテストの種類の必須の印。
 *
 * @extends Factory<NoticeRequiredType>
 */
class NoticeRequiredTypeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'test.notice',
        ];
    }
}

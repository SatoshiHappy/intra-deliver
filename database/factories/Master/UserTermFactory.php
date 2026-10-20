<?php

namespace Database\Factories\Master;

use App\Models\Master\User;
use App\Models\Master\UserTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserTerm>
 */
class UserTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'start_date' => '2020-01-01',
            'end_date' => null,
            'is_deleted' => false,
            'sex' => null,
        ];
    }
}

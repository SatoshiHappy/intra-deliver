<?php

namespace Database\Factories\Authz;

use App\Models\Authz\AuthzSubjectCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzSubjectCategory>
 */
class AuthzSubjectCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('????####'),
            'sort_key' => 0,
        ];
    }
}

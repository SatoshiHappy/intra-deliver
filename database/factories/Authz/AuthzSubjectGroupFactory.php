<?php

namespace Database\Factories\Authz;

use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzSubjectGroup>
 */
class AuthzSubjectGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'combination' => SubjectCombination::Any,
            'sort_key' => 0,
        ];
    }
}

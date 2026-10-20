<?php

namespace Database\Factories\Authz;

use App\Models\Authz\AuthzResourceGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzResourceGroup>
 */
class AuthzResourceGroupFactory extends Factory
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
            'parent_id' => null,
            'sort_key' => 0,
        ];
    }

    /**
     * 親のリソースグループの下に置く。
     */
    public function withParent(): static
    {
        return $this->state(fn (): array => ['parent_id' => AuthzResourceGroup::factory()]);
    }
}

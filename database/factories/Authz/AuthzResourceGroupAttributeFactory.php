<?php

namespace Database\Factories\Authz;

use App\Enums\Authz\ResourceGroupAttributeKey;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzResourceGroupAttribute>
 */
class AuthzResourceGroupAttributeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'authz_resource_group_id' => AuthzResourceGroup::factory(),
            'key' => fake()->unique()->bothify('????####'),
            'value' => fake()->word(),
        ];
    }

    /**
     * 閉塞の属性にする(値は ALL か、<リソースタイプ>:<アクション> をカンマで区切って並べたもの)。
     */
    public function blocked(string $value = 'ALL'): static
    {
        return $this->state(fn (): array => ['key' => ResourceGroupAttributeKey::Blocked->value, 'value' => $value]);
    }
}

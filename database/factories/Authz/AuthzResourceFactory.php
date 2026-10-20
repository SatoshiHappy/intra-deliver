<?php

namespace Database\Factories\Authz;

use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定のリソースタイプは本家のメニューグループ(im-menu-group)。URI はリソースタイプとコロンで始める(リソースタイプを変えても揃う)。
 *
 * @extends Factory<AuthzResource>
 */
class AuthzResourceFactory extends Factory
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
            'resource_type' => 'im-menu-group',
            'uri' => fn (array $attributes): string => $attributes['resource_type'].':'.fake()->unique()->bothify('????####'),
        ];
    }
}

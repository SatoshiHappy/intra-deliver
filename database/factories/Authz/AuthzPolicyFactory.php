<?php

namespace Database\Factories\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定のリソースタイプとアクションは、本家のメニューグループの参照(im-menu-group の read)。
 *
 * @extends Factory<AuthzPolicy>
 */
class AuthzPolicyFactory extends Factory
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
            'authz_subject_group_id' => AuthzSubjectGroup::factory(),
            'resource_type' => 'im-menu-group',
            'action' => 'read',
            'effect' => PolicyEffect::Permit,
        ];
    }
}

<?php

namespace Tests\Feature\Models\Authz;

use App\Models\Authz\AuthzResourceGroupAttribute;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースグループの汎用属性(docs の 設計/共通マスタ/ロールと認可/リソース.md: キーは空文字でなく、1 つのリソースグループの 1 つのキーに値は 1 つ)。
 */
final class AuthzResourceGroupAttributeTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_an_empty_key(): void
    {
        $this->assertRejectedBy('authz_resource_group_attributes_key_check', fn () => AuthzResourceGroupAttribute::factory()->create(['key' => '']));
    }

    public function test_rejects_a_second_value_for_the_same_group_and_key(): void
    {
        $existing = AuthzResourceGroupAttribute::factory()->create();

        $this->assertRejectedBy(
            'authz_resource_group_attributes_authz_resource_group_id_key_unique',
            fn () => AuthzResourceGroupAttribute::factory()->create($existing->only(['authz_resource_group_id', 'key'])),
        );
    }

    public function test_accepts_the_same_key_for_another_group(): void
    {
        $existing = AuthzResourceGroupAttribute::factory()->create();

        $other = AuthzResourceGroupAttribute::factory()->create(['key' => $existing->key]);

        $this->assertModelExists($other);
    }

    public function test_accepts_another_key_for_the_same_group(): void
    {
        $existing = AuthzResourceGroupAttribute::factory()->create();

        $other = AuthzResourceGroupAttribute::factory()->create(['authz_resource_group_id' => $existing->authz_resource_group_id]);

        $this->assertModelExists($other);
    }
}

<?php

namespace Tests\Feature\Models\Authz;

use App\Models\Authz\AuthzResourceGroup;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースグループ(docs の 設計/共通マスタ/ロールと認可/リソース.md: 自分を親にしない、親が無い行は階層の先頭)。
 */
final class AuthzResourceGroupTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_group_as_its_own_parent(): void
    {
        $group = AuthzResourceGroup::factory()->create();

        $this->assertRejectedBy('authz_resource_groups_parent_id_id_check', fn () => $group->update(['parent_id' => $group->id]));
    }

    public function test_accepts_a_group_without_a_parent_as_the_top_of_a_tree(): void
    {
        $group = AuthzResourceGroup::factory()->create(['parent_id' => null]);

        $this->assertNull($group->refresh()->parent);
    }
}

<?php

namespace Tests\Feature\Models\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 組織所属役職(docs の 設計/共通マスタ/組織とユーザー.md 8 節: 所属と役職は同じ組織セット、1 つの所属に同じ役職は 1 つ、兼任は複数持てる)。
 */
final class DepartmentMembershipPostTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_rejects_a_post_in_another_set(): void
    {
        $membership = DepartmentMembership::factory()->create();
        $postInOtherSet = CompanyPost::factory()->create();

        $this->assertRejectedBy(
            'department_membership_posts_department_set_id_company_post_id_foreign',
            fn () => DepartmentMembershipPost::factory()->create([
                'department_set_id' => $membership->department_set_id,
                'department_membership_id' => $membership->id,
                'company_post_id' => $postInOtherSet->id,
            ]),
        );
    }

    public function test_rejects_a_membership_in_another_set(): void
    {
        $post = CompanyPost::factory()->create();
        $membershipInOtherSet = DepartmentMembership::factory()->create();

        $this->assertRejectedBy(
            'department_membership_posts_department_set_id_department_membership_id_foreign',
            fn () => DepartmentMembershipPost::factory()->create([
                'department_set_id' => $post->department_set_id,
                'department_membership_id' => $membershipInOtherSet->id,
                'company_post_id' => $post->id,
            ]),
        );
    }

    public function test_rejects_the_same_post_twice_on_one_membership(): void
    {
        $existing = DepartmentMembershipPost::factory()->create();
        $samePost = $existing->only(['department_set_id', 'department_membership_id', 'company_post_id']);

        $this->assertRejectedBy(
            'department_membership_posts_department_membership_id_company_post_id_unique',
            fn () => DepartmentMembershipPost::factory()->create($samePost),
        );
    }

    public function test_accepts_several_posts_on_one_membership(): void
    {
        $existing = DepartmentMembershipPost::factory()->create();
        $anotherPost = CompanyPost::factory()->create(['department_set_id' => $existing->department_set_id]);

        $concurrent = DepartmentMembershipPost::factory()->create([
            'department_set_id' => $existing->department_set_id,
            'department_membership_id' => $existing->department_membership_id,
            'company_post_id' => $anotherPost->id,
        ]);

        $this->assertModelExists($concurrent);
    }
}

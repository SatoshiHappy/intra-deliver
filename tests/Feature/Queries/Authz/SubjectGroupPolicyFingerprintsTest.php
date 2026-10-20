<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\SubjectGroupPolicyFingerprints;
use Tests\Support\TransactionalTestCase;

/**
 * 列の削除の確かめの件数と指紋(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.2): すべての種類のポリシーを数え、指紋は並び
 * (作った順)に依らず、エフェクト・組のどれかが変わると変わる。ポリシーの無い対象者は件数 0。
 */
final class SubjectGroupPolicyFingerprintsTest extends TransactionalTestCase
{
    public function test_counts_every_type_and_the_fingerprint_does_not_depend_on_the_order_of_creation(): void
    {
        [$first, $second] = [AuthzSubjectGroup::factory()->create()->id, AuthzSubjectGroup::factory()->create()->id];
        [$a, $b] = [AuthzResourceGroup::factory()->create()->id, AuthzResourceGroup::factory()->create()->id];
        $this->policy($first, $a, 'service', 'execute', PolicyEffect::Permit);
        $this->policy($first, $b, 'im-menu-group', 'read', PolicyEffect::Deny);
        $this->policy($second, $b, 'im-menu-group', 'read', PolicyEffect::Deny);
        $this->policy($second, $a, 'service', 'execute', PolicyEffect::Permit);

        $fingerprints = app(SubjectGroupPolicyFingerprints::class)->of([$first, $second]);

        $this->assertSame(2, $fingerprints[$first]['policyCount']);
        $this->assertSame($fingerprints[$first]['fingerprint'], $fingerprints[$second]['fingerprint']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $fingerprints[$first]['fingerprint']);
    }

    public function test_the_fingerprint_changes_with_the_effect(): void
    {
        $subject = AuthzSubjectGroup::factory()->create()->id;
        $group = AuthzResourceGroup::factory()->create()->id;
        $policy = $this->policy($subject, $group, 'service', 'execute', PolicyEffect::Permit);
        $before = app(SubjectGroupPolicyFingerprints::class)->of([$subject])[$subject];

        $policy->update(['effect' => PolicyEffect::Deny]);

        $after = app(SubjectGroupPolicyFingerprints::class)->of([$subject])[$subject];
        $this->assertSame(1, $after['policyCount']);
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
    }

    public function test_a_subject_without_policies_has_no_policy(): void
    {
        $subject = AuthzSubjectGroup::factory()->create()->id;

        $this->assertSame(
            ['policyCount' => 0, 'fingerprint' => hash('sha256', '[]')],
            app(SubjectGroupPolicyFingerprints::class)->of([$subject])[$subject],
        );
    }

    private function policy(int $subject, int $group, string $type, string $action, PolicyEffect $effect): AuthzPolicy
    {
        return AuthzPolicy::query()->create([
            'authz_subject_group_id' => $subject,
            'authz_resource_group_id' => $group,
            'resource_type' => $type,
            'action' => $action,
            'effect' => $effect,
        ]);
    }
}

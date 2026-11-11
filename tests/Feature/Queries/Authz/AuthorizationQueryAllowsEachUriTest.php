<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupAttribute;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\LogsQueries;
use Tests\Support\TransactionalTestCase;

/**
 * URI をまとめた判定 {@see AuthorizationQuery::allowsEachUri()}: URI ごとの結果が単独の {@see AuthorizationQuery::allowsUri()} と同じで、
 * 問い合わせの数が URI の数で増えない(1 回の表示で多くの URI を判定するメニューのため)。
 * 結果の 4 通り = リソースが無い(禁止)・合う許可(許可)・合う禁止だけ(禁止)・閉塞(禁止)。
 */
final class AuthorizationQueryAllowsEachUriTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use LogsQueries;

    private const DATE = '2026-11-11';

    private Requester $requester;

    private AuthzSubjectGroup $mine;

    protected function setUp(): void
    {
        parent::setUp();
        $user = $this->user();
        $this->requester = new Requester($user->id, null);
        $this->mine = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $this->mine->id, 'user_id' => $user->id]);
    }

    public function test_each_result_is_the_same_as_allows_uri(): void
    {
        $uris = [
            'missing' => 'im-menu-group://group/missing',
            'permitted' => $this->resource(PolicyEffect::Permit),
            'denied' => $this->resource(PolicyEffect::Deny),
            'blocked' => $this->resource(PolicyEffect::Permit, blocked: true),
        ];
        $authorization = app(AuthorizationQuery::class);
        $date = self::day(self::DATE);

        $requester = $this->requester;
        $each = $authorization->allowsEachUri($requester, [...array_values($uris), $uris['permitted']], 'read', $date);
        $single = array_map(fn (string $uri) => $authorization->allowsUri($requester, $uri, 'read', $date), $uris);

        $this->assertSame(['missing' => false, 'permitted' => true, 'denied' => false, 'blocked' => false], $single);
        $this->assertSame(array_combine(array_values($uris), array_values($single)), $each);
    }

    public function test_the_queries_do_not_grow_with_the_uris_and_allows_uri_is_one_uri_of_them(): void
    {
        $authorization = app(AuthorizationQuery::class);
        $date = self::day(self::DATE);
        $first = $this->resource(PolicyEffect::Permit);
        $authorization->allowsUri($this->requester, $first, 'read', $date);
        $others = array_map(
            fn (int $index) => $this->resource($index % 2 === 0 ? PolicyEffect::Permit : PolicyEffect::Deny),
            range(1, 5),
        );
        $requester = $this->requester;

        $alone = $this->queriesOf(fn () => $authorization->allowsUri($requester, $first, 'read', $date));
        $one = $this->queriesOf(fn () => $authorization->allowsEachUri($requester, [$first], 'read', $date));
        $uris = [$first, ...$others];
        $six = $this->queriesOf(fn () => $authorization->allowsEachUri($requester, $uris, 'read', $date));

        $this->assertSame($alone, $one);
        $this->assertSame($one, $six);
        $this->assertSame(0, $this->queriesOf(fn () => $authorization->allowsEachUri($requester, [], 'read', $date)));
    }

    /**
     * 自分に合うポリシーを 1 つ持つリソースグループのリソースの URI(閉塞なら read の閉塞の値も置く)。
     */
    private function resource(PolicyEffect $effect, bool $blocked = false): string
    {
        $group = AuthzResourceGroup::factory()->create();
        $resource = AuthzResource::factory()
            ->create(['authz_resource_group_id' => $group->id, 'resource_type' => 'im-menu-group']);
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $group->id,
            'authz_subject_group_id' => $this->mine->id,
            'resource_type' => 'im-menu-group',
            'action' => 'read',
            'effect' => $effect,
        ]);

        if ($blocked) {
            AuthzResourceGroupAttribute::factory()->blocked('im-menu-group:read')
                ->create(['authz_resource_group_id' => $group->id]);
        }

        return $resource->uri;
    }
}

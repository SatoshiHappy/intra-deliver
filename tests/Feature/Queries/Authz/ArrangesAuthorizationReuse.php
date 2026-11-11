<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\User;
use App\Queries\Authz\Api\Authorization;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Authz\Api\Requester;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\LogsQueries;

/**
 * 判定の使い回しのテストの準備({@see AuthorizationReuseTest}・{@see AuthorizationReuseStatementsTest})。
 */
trait ArrangesAuthorizationReuse
{
    use BuildsCommonMaster;
    use LogsQueries;

    private const DATE = '2026-11-11';

    private User $user;

    private Requester $requester;

    private AuthzSubjectGroup $mine;

    private AuthzResourceGroup $group;

    private string $uri;

    /**
     * 自分だけに合う対象者に read を許したリソースの URI と、判定を求める者の事実を作っておく。
     */
    private function arrangeAllowedUri(): void
    {
        $this->user = $this->user();
        $this->requester = new Requester($this->user->id, null);
        $this->mine = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()
            ->create(['authz_subject_group_id' => $this->mine->id, 'user_id' => $this->user->id]);
        $this->group = AuthzResourceGroup::factory()->create();
        $this->uri = AuthzResource::factory()
            ->create(['authz_resource_group_id' => $this->group->id, 'resource_type' => 'im-menu-group'])->uri;
        AuthzPolicy::factory()->create([
            'authz_resource_group_id' => $this->group->id,
            'authz_subject_group_id' => $this->mine->id,
            'resource_type' => 'im-menu-group',
            'action' => 'read',
            'effect' => PolicyEffect::Permit,
        ]);
        $this->allows();
    }

    private function reuse(): AuthorizationReuse
    {
        return app(AuthorizationReuse::class);
    }

    private function allows(): bool
    {
        return app(Authorization::class)->allowsUri($this->requester, $this->uri, 'read', self::day(self::DATE));
    }

    /**
     * 自分の read の許可を禁止に書き換える(クエリビルダーの一括の書き込み = モデルのイベントを通らない)。
     */
    private function deny(): void
    {
        AuthzPolicy::query()->where('authz_resource_group_id', $this->group->id)
            ->update(['effect' => PolicyEffect::Deny]);
    }
}

<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\Role;
use App\Models\Master\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 認可設定の編集(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md)のテストの手助け: セルの切替の要求、リソースグループ・対象者の引き方、
 * ほかの人への許可の置き方、セルの直接の設定の読み方。
 */
trait EditsAuthz
{
    private const OVERVIEW_URL = '/authz/overview';

    private const CELLS_URL = '/authz/editor/cells';

    /**
     * 認可設定の画面からセルを切り替える(画面・処理の execute が既定)。
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function changeCell(
        string $groupCode,
        int $subjectGroupId,
        string $from,
        string $to,
        array $overrides = [],
    ): TestResponse {
        return $this->from(self::OVERVIEW_URL)->put(self::CELLS_URL, [
            'resource_group_id' => $this->groupId($groupCode),
            'subject_group_id' => $subjectGroupId,
            'type' => 'service',
            'action' => 'execute',
            'from' => $from,
            'to' => $to,
            ...$overrides,
        ]);
    }

    private function groupId(string $code): int
    {
        return AuthzResourceGroup::query()->where('code', $code)->sole()->id;
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    /**
     * 組み込みの列の対象者(初期データの seeder と同じ引き方)。
     */
    private function administratorColumn(): int
    {
        $roleId = Role::query()->where('code', 'system_admin')->sole()->id;

        return $this->singleCondition(new SubjectConditionInput(roleId: $roleId));
    }

    private function guestColumn(): int
    {
        return $this->singleCondition(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous));
    }

    private function authenticatedColumn(): int
    {
        return $this->singleCondition(new SubjectConditionInput(metaSubject: MetaSubject::Authenticated));
    }

    /**
     * ユーザー 1 人の列(無ければ作る)。
     */
    private function userColumn(string $userCode): int
    {
        return $this->singleCondition(new SubjectConditionInput(userId: $this->user($userCode)->id), "{$userCode} の列");
    }

    private function singleCondition(SubjectConditionInput $condition, string $name = '列'): int
    {
        return app(FindOrCreateSingleConditionSubjectGroup::class)
            ->execute($condition, ['ja' => ['display_name' => $name]])->id;
    }

    /**
     * 対象者に、リソースグループの画面・処理の execute の直接の設定を置く。
     */
    private function place(
        string $groupCode,
        int $subjectGroupId,
        PolicyEffect $effect,
        string $type = 'service',
        string $action = 'execute',
    ): void {
        app(CreatePolicy::class)->execute($this->groupId($groupCode), $subjectGroupId, $type, $action, $effect);
    }

    /**
     * セルの直接の設定(permit・deny・none)。
     */
    private function directSetting(
        string $groupCode,
        int $subjectGroupId,
        string $type = 'service',
        string $action = 'execute',
    ): string {
        $policy = AuthzPolicy::query()->where([
            'authz_resource_group_id' => $this->groupId($groupCode),
            'authz_subject_group_id' => $subjectGroupId,
            'resource_type' => $type,
            'action' => $action,
        ])->first();

        return $policy?->effect->value ?? 'none';
    }
}

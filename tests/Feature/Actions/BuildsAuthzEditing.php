<?php

namespace Tests\Feature\Actions;

use App\Actions\Authz\AddAuthzColumn;
use App\Actions\Authz\AuthzCellInput;
use App\Actions\Authz\ChangeAuthzCell;
use App\Actions\Authz\DeleteAuthzColumn;
use App\Actions\Authz\UpdatePolicyEffect;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectColumnKind;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\User;
use App\Queries\Authz\SubjectGroupPolicyFingerprints;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 共通マスタのロックの検査({@see CommonMasterLockTest})が、認可設定の編集の業務処理(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2・3 節)を呼ぶ手助け。
 * 前提のデータは factory か初期データで用意し(ロックを取らない)、業務処理だけがロックを取るようにする。守りを通すため、操作する人は
 * 初期データのシステム管理者(u0004)。
 */
trait BuildsAuthzEditing
{
    public function changeAuthzCell(): void
    {
        $cell = new AuthzCellInput(
            AuthzResourceGroup::query()->where('code', 'http-services.master.search')->sole()->id,
            AuthzSubjectGroup::factory()->create()->id,
            'service',
            'execute',
        );

        app(ChangeAuthzCell::class)->execute($this->administratorContext(), $cell, null, PolicyEffect::Permit);
    }

    public function addAuthzColumn(): AuthzSubjectGroup
    {
        $userId = User::query()->where('code', 'u0001')->sole()->id;
        $date = $this->administratorContext()->date;

        return app(AddAuthzColumn::class)->execute(SubjectColumnKind::User, $userId, $date);
    }

    public function deleteAuthzColumn(): void
    {
        $subjectGroupId = AuthzSubjectGroup::factory()->create()->id;
        $seen = app(SubjectGroupPolicyFingerprints::class)->of([$subjectGroupId])[$subjectGroupId];

        app(DeleteAuthzColumn::class)
            ->execute($this->administratorContext(), $subjectGroupId, $seen['policyCount'], $seen['fingerprint']);
    }

    public function updatePolicyEffect(): AuthzPolicy
    {
        return app(UpdatePolicyEffect::class)->execute($this->policyId(), PolicyEffect::Deny);
    }

    public function policyId(): int
    {
        return AuthzPolicy::factory()->create()->id;
    }

    private function administratorContext(): AccessContext
    {
        $userId = User::query()->where('code', 'u0004')->sole()->id;
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        return new AccessContext($userId, null, null, $today, AccessContext::LOCALE_CODE);
    }
}

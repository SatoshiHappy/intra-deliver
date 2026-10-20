<?php

namespace Database\Seeders;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use Illuminate\Database\Seeder;

/**
 * 今の利用者の初期データ(docs の 設計/デモユーザー切替/今の利用者.md 5.8、設計/メニュー/初期データ.md 4 節): 接頭辞のリソースグループ
 * http-services.account と、ルート account.logout・account.current-department のリソースとポリシー。
 *
 * ポリシー: ログアウト = ゲストユーザと認証済みユーザ(ゲストが呼んでも害が無く、押す前に利用者が外れていても 403 にしない)、
 * 所属の切替 = 認証済みユーザ(ゲストには所属が無い)。システム管理者は http-services の先頭の許可を継承する(足さない)。
 * MenuSeeder の後に 1 回入れる前提。すべて業務処理を通す。
 */
class AccountSeeder extends Seeder
{
    use FindsAuthzSubjects;

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        $this->createPrefix->execute('account', ['ja' => ['display_name' => 'アカウント'], 'en' => ['display_name' => 'Account']]);
        $logout = $this->createRouteResource->execute('account.logout', ['ja' => ['display_name' => 'ログアウト'], 'en' => ['display_name' => 'Log out']]);
        $currentDepartment = $this->createRouteResource->execute('account.current-department', ['ja' => ['display_name' => '所属の切替'], 'en' => ['display_name' => 'Switch department']]);
        $guest = $this->guestSubjectGroupId($this->findOrCreate);
        $authenticated = $this->authenticatedSubjectGroupId($this->findOrCreate);

        foreach ([[$logout, $guest], [$logout, $authenticated], [$currentDepartment, $authenticated]] as [$resource, $subjectGroupId]) {
            $this->createPolicy->execute($resource->authz_resource_group_id, $subjectGroupId, ServiceAction::RESOURCE_TYPE, ServiceAction::Execute->value, PolicyEffect::Permit);
        }
    }
}

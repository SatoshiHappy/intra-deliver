<?php

namespace Database\Seeders;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResourceGroup;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use Illuminate\Database\Seeder;

/**
 * 認可の初期データ(docs の 設計/メニュー/初期データ.md 4 節): 画面・処理のセット http-services、対象者「ゲストユーザ」「認証済みユーザ」
 * 「システム管理者」(とそのカテゴリ = 業務処理が作る)、システム管理者にセットの先頭で service・execute の許可(全部を許すロール。
 * 判定を飛ばす抜け道は作らない)。ルートのリソースは、そのルートを持つモジュールの seeder が作る: 認可の確認の画面は、接頭辞のリソースグループ
 * http-services.authz とルート authz.overview のリソース(ポリシーは足さない。システム管理者だけが先頭の許可を継承して開ける =
 * docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.3)。
 * 共通マスタの初期データ(ロール)の後に、空の認可の表へ 1 回入れる前提。すべて業務処理を通す。
 */
class AuthzSeeder extends Seeder
{
    use FindsAuthzSubjects;

    public function run(
        CreateResourceGroup $createResourceGroup,
        FindOrCreateSingleConditionSubjectGroup $findOrCreate,
        CreatePolicy $createPolicy,
        CreateRoutePrefixResourceGroup $createPrefix,
        CreateRouteResource $createRouteResource,
    ): void {
        $services = $createResourceGroup->execute(ServiceAction::SET_CODE, null, [
            'ja' => ['display_name' => '画面・処理'],
            'en' => ['display_name' => 'Screens and processes'],
        ]);

        $this->guestSubjectGroupId($findOrCreate);
        $this->authenticatedSubjectGroupId($findOrCreate);

        $createPolicy->execute(
            $services->id,
            $this->systemAdministratorSubjectGroupId($findOrCreate),
            ServiceAction::RESOURCE_TYPE,
            ServiceAction::Execute->value,
            PolicyEffect::Permit,
        );

        $createPrefix->execute('authz', ['ja' => ['display_name' => '認可'], 'en' => ['display_name' => 'Authorization']]);
        $createRouteResource->execute('authz.overview', ['ja' => ['display_name' => '認可設定'], 'en' => ['display_name' => 'Authorization']]);
    }
}

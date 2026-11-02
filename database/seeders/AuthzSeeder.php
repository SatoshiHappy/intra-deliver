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
 * 認可の初期データ(docs の 設計/メニュー/初期データ.md 4 節): 画面・処理のセット http-services と設定不可のセット http-services-unmanaged
 * (ルートの置き場 = 設計/メニュー/認可との結び付け.md 2.3)、対象者「ゲストユーザ」「認証済みユーザ」「システム管理者」(とそのカテゴリ =
 * 業務処理が作る)、システム管理者に 2 つのセットの先頭で service・execute の許可(全部を許すロール。判定を飛ばす抜け道は作らない)。
 * ルートのリソースは、そのルートを持つモジュールの seeder が作る: 認可は、接頭辞のリソースグループ http-services.authz と、その下の
 * ルートの束 http-services.authz.settings と、その直下の認可設定の画面と編集の 5 ルートのリソース(ポリシーは足さない。システム管理者だけが
 * 先頭の許可を継承して開ける = docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.3、表示名 = 同 1.3 と
 * 設計/ワークフロー/申請の周辺/認可設定の編集.md 6 節)。
 * 共通マスタの初期データ(ロール)の後に、空の認可の表へ 1 回入れる前提。すべて業務処理を通す。
 */
class AuthzSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** ルートのセット(置き場): ID => ja・en の表示名 */
    private const SETS = [
        ServiceAction::SET_CODE => ['画面・処理', 'Screens and processes'],
        ServiceAction::UNMANAGED_SET_CODE => ['HTTPサービス(設定不可)', 'HTTP services (unmanaged)'],
    ];

    /** 接頭辞のリソースグループ(置き場はどれも http-services。接頭辞 → ja・en の表示名。親が先) */
    private const PREFIXES = [
        'authz' => ['認可', 'Authorization'],
        'authz.settings' => ['認可設定', 'Authorization settings'],
    ];

    /** ルートの束「認可設定」のルート(名前 → ja・en の表示名。編集の 4 本の ja は設計の表の用途) */
    private const ROUTES = [
        'authz.settings.show' => ['認可設定の画面', 'Authorization screen'],
        'authz.settings.update-cell' => ['セルの切替', 'Change a cell'],
        'authz.settings.store-subject' => ['列を追加', 'Add a column'],
        'authz.settings.destroy-subject' => ['列を削除', 'Delete a column'],
        'authz.settings.subject-candidates' => ['列を追加の候補', 'Column candidates'],
    ];

    public function run(
        CreateResourceGroup $createResourceGroup,
        FindOrCreateSingleConditionSubjectGroup $findOrCreate,
        CreatePolicy $createPolicy,
        CreateRoutePrefixResourceGroup $createPrefix,
        CreateRouteResource $createRouteResource,
    ): void {
        $translations = fn (array $names) => [
            'ja' => ['display_name' => $names[0]],
            'en' => ['display_name' => $names[1]],
        ];
        $this->guestSubjectGroupId($findOrCreate);
        $this->authenticatedSubjectGroupId($findOrCreate);
        $administrator = $this->systemAdministratorSubjectGroupId($findOrCreate);

        foreach (self::SETS as $code => $names) {
            $set = $createResourceGroup->execute($code, null, $translations($names));
            $createPolicy->execute(
                $set->id,
                $administrator,
                ServiceAction::RESOURCE_TYPE,
                ServiceAction::Execute->value,
                PolicyEffect::Permit,
            );
        }

        foreach (self::PREFIXES as $prefix => $names) {
            $createPrefix->execute($prefix, ServiceAction::SET_CODE, $translations($names));
        }

        foreach (self::ROUTES as $routeName => $names) {
            $createRouteResource->execute($routeName, $translations($names));
        }
    }
}

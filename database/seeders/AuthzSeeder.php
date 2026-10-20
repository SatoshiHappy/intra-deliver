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
 * docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.3)。認可設定の編集も、接頭辞のリソースグループ 3 つと 4 ルートのリソース
 * だけを作る(ポリシーは足さない = docs の 設計/ワークフロー/申請の周辺/初期データ.md 3 節。表示名 = 設計/ワークフロー/申請の周辺/認可設定の編集.md 6 節)。
 * 共通マスタの初期データ(ロール)の後に、空の認可の表へ 1 回入れる前提。すべて業務処理を通す。
 */
class AuthzSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** 認可設定の編集の接頭辞のリソースグループ(接頭辞 → ja・en の表示名。親が先) */
    private const EDITOR_PREFIXES = [
        'authz.editor' => ['認可設定の編集', 'Authorization editing'],
        'authz.editor.cells' => ['セル', 'Cells'],
        'authz.editor.subjects' => ['列', 'Columns'],
    ];

    /** 認可設定の編集のルート(名前 → ja・en の表示名。ja は設計の表の用途) */
    private const EDITOR_ROUTES = [
        'authz.editor.cells.update' => ['セルの切替', 'Change a cell'],
        'authz.editor.subjects.store' => ['列を追加', 'Add a column'],
        'authz.editor.subjects.destroy' => ['列を削除', 'Delete a column'],
        'authz.editor.subject-candidates' => ['列を追加の候補', 'Column candidates'],
    ];

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

        $this->editorResources($createPrefix, $createRouteResource);
    }

    /**
     * 認可設定の編集の接頭辞のリソースグループと、ルートのリソース(ポリシーは足さない)。
     */
    private function editorResources(
        CreateRoutePrefixResourceGroup $createPrefix,
        CreateRouteResource $createRoute,
    ): void {
        $translations = fn (array $names) => [
            'ja' => ['display_name' => $names[0]],
            'en' => ['display_name' => $names[1]],
        ];

        foreach (self::EDITOR_PREFIXES as $prefix => $names) {
            $createPrefix->execute($prefix, $translations($names));
        }

        foreach (self::EDITOR_ROUTES as $routeName => $names) {
            $createRoute->execute($routeName, $translations($names));
        }
    }
}

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
 * 共通マスタの画面のルートの認可の初期データ(docs の 設計/メニュー/初期データ.md 4 節、設計/共通マスタ/検索と組織図.md 4 節)。共通マスタの初期データ
 * (CommonMasterSeeder)はセットと対象者より前に入るので、ルートの分を別の seeder にした。
 * - 組織図 master.organization-chart.show = http-services。接頭辞のリソースグループ http-services.master(フォルダ「基本データ」)と
 *   http-services.master.organization-chart(ルートの束「組織図」)と、ルートのリソース。許可はルートの束に置き、フォルダとルートには置かない
 *   (http-services の許可の置き場 = 設計/メニュー/認可との結び付け/ルートのリソース.md 2.3)
 * - 共通マスタの検索 master.search = 設定不可のセット。接頭辞のリソースグループ http-services-unmanaged.master と、ルートのリソース。
 *   許可はルートに置き、接頭辞には置かない(設定不可のセットの中の置き方)
 *
 * ポリシー: ゲストユーザと認証済みユーザの両方に execute の許可(誰にでも許すものは両方に置く)。システム管理者は 2 つのセットの先頭の許可を継承する
 * (足さない)。AuthzSeeder の後に 1 回入れる前提。すべて業務処理を通す。
 */
class CommonMasterRouteSeeder extends Seeder
{
    use FindsAuthzSubjects;
    use TranslatesRoutePrefixes;

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        $names = fn (string $ja, string $en) => ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]];
        $this->createPrefix->execute('master', ServiceAction::SET_CODE, $names('基本データ', 'Basic data'));
        $chart = $this->prefixTranslations([
            '組織図', 'Organization chart',
            '組織の階層と、組織に所属するユーザーを表示できます',
            'View the organization hierarchy and the members of each organization',
        ]);
        $bundle = $this->createPrefix->execute('master.organization-chart', ServiceAction::SET_CODE, $chart);
        $this->createPrefix->execute('master', ServiceAction::UNMANAGED_SET_CODE, $names('基本データ', 'Basic data'));
        $this->createRouteResource->execute('master.organization-chart.show', $names('組織図', 'Organization chart'));
        $search = $this->createRouteResource->execute('master.search', $names('基本データの検索', 'Basic data search'));

        $everyone = [
            $this->guestSubjectGroupId($this->findOrCreate),
            $this->authenticatedSubjectGroupId($this->findOrCreate),
        ];

        foreach ($everyone as $subjectGroupId) {
            foreach ([$bundle->id, $search->authz_resource_group_id] as $resourceGroupId) {
                $this->createPolicy->execute(
                    $resourceGroupId,
                    $subjectGroupId,
                    ServiceAction::RESOURCE_TYPE,
                    ServiceAction::Execute->value,
                    PolicyEffect::Permit,
                );
            }
        }
    }
}

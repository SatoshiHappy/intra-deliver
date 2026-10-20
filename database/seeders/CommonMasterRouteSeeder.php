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
 * 共通マスタの画面のルートの認可の初期データ(docs の 設計/メニュー/初期データ.md 4 節、設計/共通マスタ/検索と組織図.md 4 節): 接頭辞のリソースグループ
 * http-services.master と、ルート master.organization-chart・master.search のリソースとポリシー。共通マスタの初期データ(CommonMasterSeeder)はセット
 * http-services と対象者より前に入るので、ルートの分を別の seeder にした。
 *
 * ポリシー: ルートごとに、ゲストユーザと認証済みユーザの両方に execute の許可(誰にでも許すものは両方に置く)。接頭辞 http-services.master には
 * 置かない(後の段で足す共通マスタの管理画面が、ゲストへの許可を継承しないように)。システム管理者は http-services の先頭の許可を継承する(足さない)。
 * AuthzSeeder の後に 1 回入れる前提。すべて業務処理を通す。
 */
class CommonMasterRouteSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** ルート: 名前 => [表示名(ja), 表示名(en)] */
    private const ROUTES = [
        'master.organization-chart' => ['組織図', 'Organization chart'],
        'master.search' => ['共通マスタの検索', 'Common master search'],
    ];

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        $this->createPrefix->execute('master', ['ja' => ['display_name' => '共通マスタ'], 'en' => ['display_name' => 'Common master']]);
        $everyone = [$this->guestSubjectGroupId($this->findOrCreate), $this->authenticatedSubjectGroupId($this->findOrCreate)];

        foreach (self::ROUTES as $name => [$ja, $en]) {
            $resource = $this->createRouteResource->execute($name, ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]]);

            foreach ($everyone as $subjectGroupId) {
                $this->createPolicy->execute($resource->authz_resource_group_id, $subjectGroupId, ServiceAction::RESOURCE_TYPE, ServiceAction::Execute->value, PolicyEffect::Permit);
            }
        }
    }
}

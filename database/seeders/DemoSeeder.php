<?php

namespace Database\Seeders;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * デモユーザー切替の初期データ(docs の 設計/デモユーザー切替/デモの取り付け.md 6.3): 接頭辞のリソースグループ http-services.demo と、
 * ルート demo.switch-user のリソースと、ゲストユーザ・認証済みユーザへの execute の許可(選ぶ前のゲストも、選んだ後の利用者も切り替えられる)。
 * システム管理者は http-services の先頭の許可を継承する(足さない)。すべて業務処理を通す。
 *
 * DatabaseSeeder からは呼ばない。設定がオンの環境で、DatabaseSeeder の後に php artisan db:seed --class=DemoSeeder で 1 回流す。
 * オフなら何も書かずに失敗する(オフでは切替のルートが無く、接頭辞のリソースグループだけを作った後でルートのリソースを作る業務処理が拒み、
 * 途中の行が残って、オンにして流し直すと一意制約で落ちるため)。
 */
class DemoSeeder extends Seeder
{
    use FindsAuthzSubjects;

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    /**
     * @throws RuntimeException 設定 demo.enabled が true でないとき
     */
    public function run(): void
    {
        if (config('demo.enabled') !== true) {
            throw new RuntimeException('デモユーザー切替がオフ(DEMO_ENABLED が true でない)なので、切替のルートの初期データを入れない');
        }

        $this->createPrefix->execute('demo', ['ja' => ['display_name' => 'デモ'], 'en' => ['display_name' => 'Demo']]);
        $switchUser = $this->createRouteResource->execute('demo.switch-user', ['ja' => ['display_name' => '利用者の切替'], 'en' => ['display_name' => 'Switch user']]);

        foreach ([$this->guestSubjectGroupId($this->findOrCreate), $this->authenticatedSubjectGroupId($this->findOrCreate)] as $subjectGroupId) {
            $this->createPolicy->execute($switchUser->authz_resource_group_id, $subjectGroupId, ServiceAction::RESOURCE_TYPE, ServiceAction::Execute->value, PolicyEffect::Permit);
        }
    }
}

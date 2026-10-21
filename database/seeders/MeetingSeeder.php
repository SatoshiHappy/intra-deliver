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
 * 会議の初期データ(docs の 設計/業務ツール/会議/処理.md の 3.2・3.7): 接頭辞のリソースグループ http-services.meeting と、作成・再作成の 2 ルートの
 * リソースと、ゲストユーザと認証済みユーザへの execute の許可(4)。ルートは通しても、作成・再作成の可否は会議の業務モジュールの許可と
 * ゲストの判定で業務処理が決める(入れ物の業務の画面のルートと同じ形)。システム管理者は http-services の先頭の許可を継承する。すべて業務処理を通す。
 */
class MeetingSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** ルート: 名前 => [表示名(ja), 表示名(en)] */
    private const ROUTES = [
        'meeting.store' => ['会議の作成', 'Create meeting'],
        'meeting.regenerate' => ['議事録の再作成', 'Regenerate minutes'],
    ];

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        $this->createPrefix->execute('meeting', self::displayNames('会議', 'Meetings'));
        $everyone = [
            $this->guestSubjectGroupId($this->findOrCreate),
            $this->authenticatedSubjectGroupId($this->findOrCreate),
        ];

        foreach (self::ROUTES as $name => [$ja, $en]) {
            $resource = $this->createRouteResource->execute($name, self::displayNames($ja, $en));

            foreach ($everyone as $subjectGroupId) {
                $this->createPolicy->execute(
                    $resource->authz_resource_group_id,
                    $subjectGroupId,
                    ServiceAction::RESOURCE_TYPE,
                    ServiceAction::Execute->value,
                    PolicyEffect::Permit,
                );
            }
        }
    }

    /**
     * @return array{ja: array{display_name: string}, en: array{display_name: string}}
     */
    private static function displayNames(string $ja, string $en): array
    {
        return ['ja' => ['display_name' => $ja], 'en' => ['display_name' => $en]];
    }
}

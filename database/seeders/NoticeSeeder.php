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
 * 通知の初期データ(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節): 接頭辞のリソースグループ http-services.notice と、
 * 通知の 4 ルートのリソースと、ゲストユーザと認証済みユーザへの execute の許可(8)。ゲストは通れても、一覧は空で既読は 403
 * (見られる範囲と可否は読み取りと業務処理が絞る)。システム管理者は http-services の先頭の許可を継承する(足さない)。すべて業務処理を通す。
 */
class NoticeSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** ルート: 名前 => [表示名(ja), 表示名(en)] */
    private const ROUTES = [
        'notice.index' => ['通知の一覧', 'Notification list'],
        'notice.read' => ['通知を開く', 'Open notification'],
        'notice.read-all' => ['すべて既読にする', 'Mark all as read'],
        'notice.latest' => ['最新の通知', 'Latest notifications'],
    ];

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        $this->createPrefix->execute('notice', self::displayNames('通知', 'Notifications'));
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

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
 * 通知の初期データ(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節、
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/ルートと文言と検査.md の 5 節): 2 つのセットの接頭辞のリソースグループと、
 * 利用者のルート(設定不可のセット)のリソースとゲストユーザ・認証済みユーザへの execute の許可、ルートの束「通知の管理」のルートのリソース。
 * ゲストは通れても、見られる範囲と可否は読み取りと業務処理が絞る。システム管理者は 2 つのセットの先頭の許可を継承する(足さない)。
 * 受け取りの設定と必須の印は入れない(初期データへ戻すと「すべて受け取る・必須なし」に戻る = 同じ文書の 6 節)。すべて業務処理を通す。
 */
class NoticeSeeder extends Seeder
{
    use FindsAuthzSubjects;
    use TranslatesRoutePrefixes;

    /** 接頭辞のリソースグループ: セット => [接頭辞 => [表示名(ja), 表示名(en), 説明(ja)?, 説明(en)?]](説明はルートの束だけ) */
    private const PREFIXES = [
        ServiceAction::UNMANAGED_SET_CODE => [
            'notice' => ['通知', 'Notifications'],
            'notice.settings' => ['通知の設定', 'Notification settings'],
        ],
        ServiceAction::SET_CODE => [
            'notice' => ['通知', 'Notifications'],
            'notice.admin' => [
                '通知の管理', 'Notification administration',
                '通知の種類ごとに、必須にするかを変更できます', 'Change whether each notification type is required',
            ],
        ],
    ];

    /** 利用者のルート(どれもゲストユーザと認証済みユーザに execute を許す): 名前 => [表示名(ja), 表示名(en)] */
    private const ROUTES = [
        'notice.index' => ['通知の一覧', 'Notification list'],
        'notice.read' => ['通知を開く', 'Open notification'],
        'notice.read-all' => ['すべて既読にする', 'Mark all as read'],
        'notice.latest' => ['最新の通知', 'Latest notifications'],
        'notice.settings.show' => ['通知の設定', 'Notification settings'],
        'notice.settings.update' => ['通知の設定の保存', 'Save notification settings'],
    ];

    /** システム管理者だけのルート(ポリシーを置かない): 名前 => [表示名(ja), 表示名(en)] */
    private const ADMIN_ROUTES = [
        'notice.admin.show' => ['通知の管理', 'Notification administration'],
        'notice.admin.update' => ['必須の通知の保存', 'Save required notifications'],
    ];

    public function __construct(
        private CreateRoutePrefixResourceGroup $createPrefix,
        private CreateRouteResource $createRouteResource,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        foreach (self::PREFIXES as $set => $prefixes) {
            foreach ($prefixes as $prefix => $names) {
                $this->createPrefix->execute($prefix, $set, $this->prefixTranslations($names));
            }
        }

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

        foreach (self::ADMIN_ROUTES as $name => [$ja, $en]) {
            $this->createRouteResource->execute($name, self::displayNames($ja, $en));
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

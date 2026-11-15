<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Notice\NoticeOptOut;
use App\Models\Notice\NoticeRequiredType;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の初期データ(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節、
 * 設計/ワークフロー/申請の周辺/通知の受け取りの設定/ルートと文言と検査.md の 5 節・6 節): 2 つのセットの接頭辞のリソースグループとルートのリソースの
 * 親と表示名、束「通知の管理」の説明。受け取りの設定と必須の印は入れない。ポリシーは AuthorizationSeedersTest が確かめる。
 */
final class NoticeSeederTest extends TransactionalTestCase
{
    private const UNMANAGED = 'http-services-unmanaged';

    public function test_seeds_the_route_resources_of_the_unmanaged_set(): void
    {
        $notice = self::UNMANAGED.'.notice';
        $settings = "{$notice}.settings";

        $this->assertSame([
            [$notice, self::UNMANAGED, '通知', 'Notifications'],
            [$settings, $notice, '通知の設定', 'Notification settings'],
            ["{$notice}.index", $notice, '通知の一覧', 'Notification list'],
            ["{$notice}.read", $notice, '通知を開く', 'Open notification'],
            ["{$notice}.read-all", $notice, 'すべて既読にする', 'Mark all as read'],
            ["{$notice}.latest", $notice, '最新の通知', 'Latest notifications'],
            ["{$settings}.show", $settings, '通知の設定', 'Notification settings'],
            ["{$settings}.update", $settings, '通知の設定の保存', 'Save notification settings'],
        ], $this->groupsUnder($notice));
    }

    public function test_seeds_the_folder_and_the_bundle_of_http_services(): void
    {
        $admin = 'http-services.notice.admin';

        $this->assertSame([
            ['http-services.notice', 'http-services', '通知', 'Notifications'],
            [$admin, 'http-services.notice', '通知の管理', 'Notification administration'],
            ["{$admin}.show", $admin, '通知の管理', 'Notification administration'],
            ["{$admin}.update", $admin, '必須の通知の保存', 'Save required notifications'],
        ], $this->groupsUnder('http-services.notice'));
        $this->assertSame(
            ['ja' => '通知の種類ごとに、必須にするかを変更できます', 'en' => 'Change whether each notification type is required'],
            [
                'ja' => $this->translation($admin, 'ja')->description,
                'en' => $this->translation($admin, 'en')->description,
            ],
        );
    }

    /**
     * 初期データ(テストの基底が入れたもの)の後、受け取りの設定と必須の印の表は 0 行(初期データへ戻すと「すべて受け取る・必須なし」)。
     */
    public function test_the_seeded_data_has_no_setting_and_no_required_type(): void
    {
        $this->assertSame([0, 0], [NoticeOptOut::query()->count(), NoticeRequiredType::query()->count()]);
    }

    /**
     * 接頭辞のリソースグループとその子孫(作った順): [ID, 親の ID, 表示名(ja), 表示名(en)]。
     *
     * @return list<array{string, string|null, string, string}>
     */
    private function groupsUnder(string $code): array
    {
        return array_values(AuthzResourceGroup::query()->with(['parent', 'authzResourceGroupTranslations.locale'])
            ->where(fn ($query) => $query->where('code', $code)->orWhere('code', 'like', "{$code}.%"))
            ->orderBy('id')->get()
            ->map(fn (AuthzResourceGroup $group) => [
                $group->code,
                $group->parent?->code,
                $this->displayName($group, 'ja'),
                $this->displayName($group, 'en'),
            ])
            ->all());
    }

    private function displayName(AuthzResourceGroup $group, string $localeCode): string
    {
        return $group->authzResourceGroupTranslations
            ->firstOrFail(fn (AuthzResourceGroupTranslation $row) => $row->locale?->code === $localeCode)
            ->display_name;
    }

    private function translation(string $code, string $localeCode): AuthzResourceGroupTranslation
    {
        return AuthzResourceGroup::query()->where('code', $code)->with('authzResourceGroupTranslations.locale')->sole()
            ->authzResourceGroupTranslations
            ->firstOrFail(fn (AuthzResourceGroupTranslation $row) => $row->locale?->code === $localeCode);
    }
}

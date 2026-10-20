<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の初期データ(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 4 節): 接頭辞のリソースグループ http-services.notice と 4 ルートのリソースの
 * 親と表示名。ポリシー 8 は AuthorizationSeedersTest が確かめる。
 */
final class NoticeSeederTest extends TransactionalTestCase
{
    public function test_seeds_the_route_resources_of_the_design(): void
    {
        $groups = AuthzResourceGroup::query()->with(['parent', 'authzResourceGroupTranslations.locale'])
            ->where('code', 'like', 'http-services.notice%')->orderBy('id')->get()
            ->map(fn (AuthzResourceGroup $group) => [
                $group->code,
                $group->parent?->code,
                $this->displayName($group, 'ja'),
                $this->displayName($group, 'en'),
            ])
            ->all();

        $this->assertSame([
            ['http-services.notice', 'http-services', '通知', 'Notifications'],
            ['http-services.notice.index', 'http-services.notice', '通知の一覧', 'Notification list'],
            ['http-services.notice.read', 'http-services.notice', '通知を開く', 'Open notification'],
            ['http-services.notice.read-all', 'http-services.notice', 'すべて既読にする', 'Mark all as read'],
            ['http-services.notice.latest', 'http-services.notice', '最新の通知', 'Latest notifications'],
        ], $groups);
    }

    private function displayName(AuthzResourceGroup $group, string $localeCode): string
    {
        return $group->authzResourceGroupTranslations
            ->firstOrFail(fn (AuthzResourceGroupTranslation $row) => $row->locale?->code === $localeCode)
            ->display_name;
    }
}

<?php

namespace Tests\Feature\Seeders;

use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzResourceGroupTranslation;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の初期データ(docs の 設計/業務ツール/会議/処理.md の 3.2・3.7): 接頭辞のリソースグループ http-services.meeting と 2 ルートのリソースの
 * 親と表示名。ポリシー 4 は AuthorizationSeedersTest が確かめる。
 */
final class MeetingSeederTest extends TransactionalTestCase
{
    public function test_seeds_the_route_resources_of_the_design(): void
    {
        $groups = AuthzResourceGroup::query()->with(['parent', 'authzResourceGroupTranslations.locale'])
            ->where('code', 'like', 'http-services.meeting%')->orderBy('id')->get()
            ->map(fn (AuthzResourceGroup $group) => [
                $group->code,
                $group->parent?->code,
                $this->displayName($group, 'ja'),
                $this->displayName($group, 'en'),
            ])
            ->all();

        $this->assertSame([
            ['http-services.meeting', 'http-services', '会議', 'Meetings'],
            ['http-services.meeting.store', 'http-services.meeting', '会議の作成', 'Create meeting'],
            ['http-services.meeting.regenerate', 'http-services.meeting', '議事録の再作成', 'Regenerate minutes'],
        ], $groups);
    }

    private function displayName(AuthzResourceGroup $group, string $localeCode): string
    {
        return $group->authzResourceGroupTranslations
            ->firstOrFail(fn (AuthzResourceGroupTranslation $row) => $row->locale?->code === $localeCode)
            ->display_name;
    }
}

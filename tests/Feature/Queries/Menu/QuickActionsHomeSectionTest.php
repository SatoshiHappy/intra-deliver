<?php

namespace Tests\Feature\Queries\Menu;

use App\Models\Authz\AuthzPolicy;
use App\Models\Master\User;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Menu\QuickActionsHomeSection;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * ホームの主な操作の節(docs の 設計/メニュー/ホーム.md 6.5・6.6): カテゴリ home_quick_actions_pc の表示判定を通ったアイテムを、並びの順に 1 枚ずつタイルにする。
 * 出し分けはポリシーだけ(ゲストはグループの read が無いので null、ルートの execute を許されないタイルは出ない)。初期データ(メニューの初期データ 4 節)を使う。
 *
 * @phpstan-import-type HomeTilesSection from HomeSections
 */
final class QuickActionsHomeSectionTest extends TransactionalTestCase
{
    private const DATE = '2026-10-15';

    public function test_a_guest_gets_null(): void
    {
        $this->assertNull($this->section(null));
    }

    public function test_an_authenticated_user_gets_the_four_tiles_in_order(): void
    {
        $this->assertSame([
            'code' => 'menu-quick-actions',
            'kind' => 'tiles',
            'area' => 'side',
            'heading' => '主な操作',
            'items' => [
                ['code' => 'home-quick-actions-workflow-flows-index', 'label' => '申請する', 'icon' => 'send', 'url' => '/workflow/flows', 'isExternal' => false],
                ['code' => 'home-quick-actions-workflow-cases-index-pending', 'label' => '承認する', 'icon' => 'inbox', 'url' => '/workflow/cases?tab=pending', 'isExternal' => false],
                ['code' => 'home-quick-actions-workflow-cases-index-processed', 'label' => '自分の申請', 'icon' => 'folder', 'url' => '/workflow/cases?tab=processed', 'isExternal' => false],
                ['code' => 'home-quick-actions-master-organization-chart', 'label' => '組織図', 'icon' => 'network', 'url' => '/master/organization-chart', 'isExternal' => false],
            ],
        ], $this->section($this->user('u0001')));
    }

    /**
     * アイテムが指すルートの execute を許されなければ、そのタイルは出ない(サイドバーと同じ表示判定。ポリシーを TX の中で消す)。
     */
    public function test_a_tile_whose_route_is_not_permitted_is_left_out(): void
    {
        AuthzPolicy::query()->whereRelation('authzResourceGroup', 'code', 'http-services.master.organization-chart')->delete();

        $this->assertSame(
            ['home-quick-actions-workflow-flows-index', 'home-quick-actions-workflow-cases-index-pending', 'home-quick-actions-workflow-cases-index-processed'],
            array_column($this->section($this->user('u0001'))['items'] ?? [], 'code'),
        );
    }

    /**
     * 表示するアイテムが無ければ null(グループの read を TX の中で消す)。
     */
    public function test_without_any_visible_item_it_returns_null(): void
    {
        AuthzPolicy::query()->whereRelation('authzResourceGroup', 'code', 'im-menu-group-home-quick-actions')->delete();

        $this->assertNull($this->section($this->user('u0001')));
    }

    /**
     * @return HomeTilesSection|null
     */
    private function section(?User $user): ?array
    {
        return app(QuickActionsHomeSection::class)->section(new AccessContext($user?->id, null, null, CarbonImmutable::parse(self::DATE, AccessContext::TIMEZONE), AccessContext::LOCALE_CODE));
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}

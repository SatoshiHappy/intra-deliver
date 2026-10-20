<?php

namespace Tests\Feature\Http\Controllers\Web\Menu;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuItemInput;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Menu\Menu;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 参照を禁止した業務モジュールのアイテム(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 1.1・1.2・1.3)を、各画面の出力で確かめる。
 * 初期データの業務モジュールとそのアイテムを使い、ルートの認可を外さずに開く。
 */
final class RecordModuleMenuItemsTest extends TransactionalTestCase
{
    use UsesSeededRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_hides_the_item_of_a_module_whose_read_is_denied_from_the_sidebar_and_the_sitemap(): void
    {
        $this->deny('project', RecordModuleAction::Read);

        $response = $this->get(route('menu.sitemap'));

        $this->assertSame(
            ['global-nav-main-records-project_issue'],
            $this->recordItemCodes($response, 'globalNavigation'),
        );
        $this->assertSame(['sitemap-main-records-project_issue'], $this->recordItemCodes($response, 'groups'));
    }

    /**
     * 判定を覚えない: 同じ人の次の要求から変わる。
     */
    public function test_the_items_change_from_the_next_request_after_the_policy_changes(): void
    {
        $before = $this->get(route('menu.sitemap'));
        $this->deny('project_issue', RecordModuleAction::Read);
        $after = $this->get(route('menu.sitemap'));

        $both = ['sitemap-main-records-project', 'sitemap-main-records-project_issue'];
        $this->assertSame($both, $this->recordItemCodes($before, 'groups'));
        $this->assertSame(['sitemap-main-records-project'], $this->recordItemCodes($after, 'groups'));
    }

    public function test_hides_the_quick_action_of_a_module_whose_read_is_denied_and_shows_it_while_permitted(): void
    {
        $root = Menu::query()->where('code', 'home-quick-actions')->sole();
        $item = new MenuItemInput('home-quick-actions-records-project', '/records/data/project', ['ja' => 'プロジェクト']);
        app(AddMenu::class)->execute($root->id, $item);
        $user = User::query()->where('code', 'u0001')->sole();

        $permitted = $this->actingAs($user)->get('/');
        $this->deny('project', RecordModuleAction::Read);
        $denied = $this->actingAs($user)->get('/');

        $this->assertContains('home-quick-actions-records-project', $this->quickActionCodes($permitted));
        $this->assertNotContains('home-quick-actions-records-project', $this->quickActionCodes($denied));
        $this->assertContains('home-quick-actions-master-organization-chart', $this->quickActionCodes($denied));
    }

    /**
     * 業務の一覧を開いた要求でも、ほかの業務モジュールのアイテムはその業務モジュールの read で決まる
     * (開いた画面のルートの引数を使わない)。
     */
    public function test_on_the_list_of_one_module_the_other_items_follow_their_own_modules(): void
    {
        $this->deny('project', RecordModuleAction::Read);

        $response = $this->get('/records/data/project_issue');

        $response->assertOk();
        $this->assertSame(
            ['global-nav-main-records-project_issue'],
            $this->recordItemCodes($response, 'globalNavigation'),
        );
        $this->assertSame('project_issue', $response->inertiaProps('module.code'));
    }

    /**
     * 隠しても URL での到達は変えない: 隠れたアイテムの URL を開くと、業務の画面の判定のとおり 403。
     */
    public function test_opening_the_url_of_a_hidden_item_is_still_forbidden(): void
    {
        $this->deny('project', RecordModuleAction::Read);

        $this->get('/records/data/project')->assertForbidden();
        $this->get('/records/data/project/')->assertForbidden();
    }

    /**
     * グループ「業務」の、業務の一覧のアイテムのメニュー ID(並びの順)。
     * グループの一覧の prop = globalNavigation(サイドバー)か groups(サイトマップ)。
     *
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function recordItemCodes(TestResponse $response, string $prop): array
    {
        $groups = $response->inertiaProps($prop);
        $this->assertIsArray($groups);
        $isMain = fn (mixed $group) => str_ends_with((string) data_get($group, 'code'), '-main');
        $main = array_values(array_filter($groups, $isMain));
        $codes = $this->codesOf(data_get($main, '0.children'));

        return array_values(array_filter($codes, fn (string $code) => str_contains($code, '-records-')));
    }

    /**
     * ホームの主な操作のタイルのメニュー ID。
     *
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function quickActionCodes(TestResponse $response): array
    {
        $sections = $response->inertiaProps('sections');
        $this->assertIsArray($sections);
        $isQuickActions = fn (mixed $section) => data_get($section, 'code') === 'menu-quick-actions';
        $quickActions = array_values(array_filter($sections, $isQuickActions));

        return $this->codesOf(data_get($quickActions, '0.items'));
    }

    /**
     * 節点の一覧(グループの子・タイル)のメニュー ID。
     *
     * @return list<string>
     */
    private function codesOf(mixed $nodes): array
    {
        $this->assertIsArray($nodes);

        return array_map(fn (mixed $node) => (string) data_get($node, 'code'), array_values($nodes));
    }
}

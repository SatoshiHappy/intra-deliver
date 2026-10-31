<?php

namespace Tests\Feature\Queries\Menu;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuItemInput;
use App\Models\Master\User;
use App\Models\Menu\Menu;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\CurrentApp;
use App\Queries\Menu\MenuDisplayQuery;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 今のアプリ(docs の 設計/メニュー/表示.md 3.3.2 のデシジョン表・3.3.7 の「今のアプリ」): アプリとアイテムをテストで置き(初期データのカテゴリ
 * im_global_nav_pc と admin_menu_pc に、テストのルート /test/… を指すアイテム。初期データのアイテムは /test と先頭の区分が違うので k = 0)、
 * 判定を求める者に表示するアプリ(2 つのカテゴリの表示判定)を渡して {@see CurrentApp::of()} を呼ぶ。判定を求める者はゲスト
 * (グループの read はゲストにだけ許す)。best と候補の数の組み合わせは単体テスト(tests/Unit/Queries/Menu/CurrentAppTest.php)。
 */
final class CurrentAppTest extends TransactionalTestCase
{
    use ArrangesMenuDisplay;

    private const LAUNCHER = MenuDisplayQuery::GLOBAL_NAVIGATION_CATEGORY;

    private const ADMIN_MENU = MenuDisplayQuery::ADMIN_MENU_CATEGORY;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addTestRoutes();
        $this->arrangeAuthorization();
    }

    /**
     * 1 行目: best が 0(どのアイテムとも先頭の区分が違う)なら無し。
     */
    public function test_a_path_matching_no_item_has_no_current_app(): void
    {
        $this->placeApp('t-a', '/test/permitted');

        $this->assertSame(['currentApp' => null, 'currentItemPath' => null], $this->currentOf('/elsewhere/1'));
    }

    /**
     * 2 行目: 候補 1 つで表示されるならその候補。現在地のパスは今のアプリの表示するアイテムの中で決まる。
     */
    public function test_the_only_candidate_shown_to_the_requester_is_the_current_app(): void
    {
        $this->placeApp('t-a', '/test/permitted');
        $this->placeApp('t-b', '/menu/sitemap');

        $this->assertSame(
            ['currentApp' => 't-a', 'currentItemPath' => '/test/permitted'],
            $this->currentOf('/test/permitted/1'),
        );
    }

    /**
     * 3 行目: 候補 2 つ以上なら並びで先(カテゴリの中は sort_number、カテゴリをまたぐとランチャー → 歯車。歯車の並びの値が小さくても後ろ)。
     */
    public function test_among_candidates_the_first_in_the_order_is_the_current_app(): void
    {
        $this->placeApp('t-admin', '/test/permitted', self::ADMIN_MENU, sortNumber: 0);
        $this->placeApp('t-later', '/test/permitted', sortNumber: 2);
        $this->placeApp('t-first', '/test/permitted', sortNumber: 1);

        $this->assertSame('t-first', $this->currentOf('/test/permitted')['currentApp']);
    }

    /**
     * 4 行目(グループの read を外す): 選んだアプリが表示されなければ無し。
     */
    public function test_the_chosen_app_whose_read_is_not_permitted_gives_no_current_app(): void
    {
        $this->placeApp('t-a', '/test/permitted', isReadPermitted: false);

        $this->assertSame(['currentApp' => null, 'currentItemPath' => null], $this->currentOf('/test/permitted/1'));
    }

    /**
     * 4 行目(表示するアイテムを全部外す): 選んだアプリのアイテムがどれも表示されない(ルートの execute が無い)なら、表示されないアプリなので無し。
     * 隠したアイテムも今のアプリを決める入力に入る(表示判定をしない)ので、表示される後ろのアプリに移らない。
     */
    public function test_the_chosen_app_without_any_visible_item_gives_no_current_app(): void
    {
        $this->placeApp('t-a', '/test/forbidden', sortNumber: 1);
        $this->placeApp('t-b', '/test/permitted', sortNumber: 2);

        $this->assertSame(['currentApp' => null, 'currentItemPath' => null], $this->currentOf('/test/forbidden/1'));
    }

    /**
     * 4 行目(候補 2 つ以上で、先が見えず後ろが見える): 次の候補には移らず無し。
     */
    public function test_when_the_first_candidate_is_not_shown_the_next_candidate_is_not_taken(): void
    {
        $this->placeApp('t-a', '/test/permitted', sortNumber: 1, isReadPermitted: false);
        $this->placeApp('t-b', '/test/permitted', sortNumber: 2);

        $this->assertSame(['currentApp' => null, 'currentItemPath' => null], $this->currentOf('/test/permitted/1'));
    }

    /**
     * 外部サイトのアイテム(パスが画面と同じでも)とパス / のアイテムは今のアプリを決めず、現在地のパスにもならない。
     */
    public function test_external_site_items_and_items_of_the_root_path_do_not_decide(): void
    {
        $first = $this->placeApp('t-a', 'http://localhost/test/permitted/1', sortNumber: 1);
        $this->item($first, 't-a-root', '/', sortNumber: 1);
        $this->placeApp('t-b', '/test/permitted', sortNumber: 2);

        $this->assertSame(
            ['currentApp' => 't-b', 'currentItemPath' => '/test/permitted'],
            $this->currentOf('/test/permitted/1'),
        );
        $this->assertSame(['currentApp' => null, 'currentItemPath' => null], $this->currentOf('/'));
    }

    /**
     * 今のアプリでも、上の階層のアイテムが無ければ現在地のパスは null。
     */
    public function test_the_current_item_path_is_null_without_an_item_at_or_above(): void
    {
        $this->placeApp('t-a', '/test/permitted');

        $this->assertSame(['currentApp' => 't-a', 'currentItemPath' => null], $this->currentOf('/test/other'));
    }

    /**
     * 覚えない: 同じ人が同じ URL を 2 回開くと同じ結果。
     */
    public function test_the_same_requester_gets_the_same_result_for_the_same_path_twice(): void
    {
        $this->placeApp('t-a', '/test/permitted', sortNumber: 1);
        $this->placeApp('t-b', '/menu/sitemap', sortNumber: 2);

        $first = $this->currentOf('/test/permitted/1');
        $this->currentOf('/menu/sitemap');

        $this->assertSame($first, $this->currentOf('/test/permitted/1'));
        $this->assertSame('t-a', $first['currentApp']);
    }

    /**
     * 同じ URL を、見られる人(ゲスト)と見られない人(グループの read を許していない認証済みの利用者)で開く。
     */
    public function test_the_same_path_gives_the_app_to_a_viewer_and_nothing_to_a_non_viewer(): void
    {
        $this->placeApp('t-a', '/test/permitted');
        $user = User::query()->where('code', 'u0001')->sole();

        $this->assertSame('t-a', $this->currentOf('/test/permitted/1')['currentApp']);
        $this->assertNull($this->currentOf('/test/permitted/1', new Requester($user->id, null))['currentApp']);
    }

    /**
     * アプリ(グループ)とアイテム 1 つを置く。
     */
    private function placeApp(
        string $code,
        string $url,
        string $category = self::LAUNCHER,
        int $sortNumber = 0,
        bool $isReadPermitted = true,
    ): Menu {
        $root = $this->group($code, $category, $isReadPermitted, $sortNumber);
        app(AddMenu::class)->execute($root->id, new MenuItemInput("{$code}-item", $url, []));

        return $root;
    }

    /**
     * @return array{currentApp: string|null, currentItemPath: string|null}
     */
    private function currentOf(string $path, ?Requester $requester = null): array
    {
        $requester ??= new Requester(null, null);
        $date = CarbonImmutable::parse('2026-10-04');
        $menus = app(MenuDisplayQuery::class);
        $visible = [
            ...$menus->visibleGroups(self::LAUNCHER, $requester, $date, 'ja'),
            ...$menus->visibleGroups(self::ADMIN_MENU, $requester, $date, 'ja'),
        ];

        return app(CurrentApp::class)->of(ltrim($path, '/') === '' ? '/' : ltrim($path, '/'), $visible);
    }
}

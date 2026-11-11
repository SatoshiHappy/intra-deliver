<?php

namespace Tests\Feature\Queries\Menu;

use App\Actions\Authz\CreateRouteResource;
use Illuminate\Support\Facades\Route;
use Tests\Support\LogsQueries;
use Tests\Support\TransactionalTestCase;

/**
 * メニューの表示判定の問い合わせの数が、グループとアイテム(指すルート)の数で増えない(docs の 設計/メニュー/表示.md 3.1。
 * グループの read とルートの execute は、それぞれ 1 回のまとめた判定で行う = 全画面の骨格の共有が毎回判定するため)。
 * テストのルートと認可は {@see ArrangesMenuDisplay} で足す。
 */
final class MenuDisplayQueryCountTest extends TransactionalTestCase
{
    use ArrangesMenuDisplay;
    use LogsQueries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addTestRoutes();
        $this->arrangeAuthorization();
    }

    public function test_the_queries_do_not_grow_with_the_groups_and_the_items(): void
    {
        $first = $this->group('first');
        $this->item($first, 'first-permitted', '/test/permitted');
        $this->visibleGroups();
        $this->resetRequestScope();
        $before = $this->queriesOf(fn () => $this->visibleGroups());

        foreach (range(1, 3) as $index) {
            $group = $this->group("more-{$index}", sortNumber: $index, isReadPermitted: $index !== 3);
            $this->item($group, "more-{$index}-route", $this->routeOf($index));
            $this->item($group, "more-{$index}-forbidden", '/test/forbidden', sortNumber: 1);
        }

        $groups = $this->visibleGroups();
        $this->resetRequestScope();
        $after = $this->queriesOf(fn () => $this->visibleGroups());

        $children = array_map(fn (array $group) => array_column($group['children'], 'code'), $groups);
        $this->assertSame([['first-permitted'], ['more-1-route']], $children);
        $this->assertSame($before, $after);
    }

    /**
     * 足すアイテムが指すルート(奇数 = ゲストに execute を許す新しいルート、偶数 = 許可の無い新しいルート)。
     */
    private function routeOf(int $index): string
    {
        $name = "test.more-{$index}";
        Route::get("/test/more-{$index}", fn () => 'ok')->name($name);
        $resource = app(CreateRouteResource::class)->execute($name, []);

        if ($index % 2 === 1) {
            $this->permitGuest($resource->authz_resource_group_id, 'service', 'execute');
        }

        return "/test/more-{$index}";
    }
}

<?php

namespace Tests\Feature\Queries\Menu;

use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuItemInput;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\MenuDisplayQuery;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 表示するアイテムの href と、平らにした表示するアイテム(docs の 設計/メニュー/表示.md 3.1 の「`href` と引数」、設計/メニュー/ホーム.md 6.5・6.6)。
 * href = url に引数を名前の順のクエリ文字列(Arr::query = RFC 3986)にして付け、? があれば &、フラグメントの前に入れる。
 * テストのルートと認可は {@see ArrangesMenuDisplay} で足す。
 */
final class MenuDisplayItemsTest extends TransactionalTestCase
{
    use ArrangesMenuDisplay;

    /**
     * href の規則(表示.md 3.1 の「`href` と引数」)の 4 通り: URL・引数・期待する href。
     *
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function hrefs(): array
    {
        return [
            '引数なし' => ['/test/permitted', [], '/test/permitted'],
            '引数あり(名前の順・RFC 3986 の符号化)' => ['/test/permitted', ['tab' => 'pending', 'a b' => 'x&y/ä'], '/test/permitted?a%20b=x%26y%2F%C3%A4&tab=pending'],
            '? を含む外部サイトの URL' => ['https://example.com/docs?page=1', ['tab' => 'pending'], 'https://example.com/docs?page=1&tab=pending'],
            'フラグメントを含む外部サイトの URL' => ['https://example.com/docs#top', ['tab' => 'pending'], 'https://example.com/docs?tab=pending#top'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->addTestRoutes();
    }

    /**
     * @param  array<string, string>  $arguments
     */
    #[DataProvider('hrefs')]
    public function test_the_href_is_the_url_with_the_arguments_as_the_query_string(string $url, array $arguments, string $expected): void
    {
        $this->arrangeAuthorization();
        app(AddMenu::class)->execute($this->group('main')->id, new MenuItemInput('item', $url, [], arguments: $arguments));

        $this->assertSame([$expected], array_column($this->visibleGroups()[0]['children'], 'href'));
    }

    /**
     * 平らにした表示するアイテム: グループの並びの順に、フォルダの中はフォルダの位置に入る(前順)。表示しないアイテムは入らない。形と href はグループの木と同じ。
     */
    public function test_visible_items_are_the_visible_items_of_the_groups_flattened_in_order(): void
    {
        $this->arrangeAuthorization();
        $second = $this->group('second', sortNumber: 1);
        $this->item($second, 'second-item', '/test/permitted');
        $first = $this->group('first');
        $this->item($first, 'first-last', '/test/permitted', sortNumber: 2);
        $this->item($first, 'first-forbidden', '/test/forbidden', sortNumber: 1);
        $folder = $this->folder($first, 'first-folder', sortNumber: 1);
        $this->item($folder, 'in-folder', '/test/permitted', ['ja' => 'フォルダの中']);
        app(AddMenu::class)->execute($first->id, new MenuItemInput('first-head', '/test/permitted', [], icon32: 'map', arguments: ['tab' => 'x']));

        $items = app(MenuDisplayQuery::class)->visibleItems(self::GLOBAL_NAVIGATION, new Requester(null, null), CarbonImmutable::parse('2026-10-04'), 'ja');

        $this->assertSame(['first-head', 'in-folder', 'first-last', 'second-item'], array_column($items, 'code'));
        $this->assertSame(['code' => 'first-head', 'type' => 'item', 'name' => null, 'icon' => 'map', 'href' => '/test/permitted?tab=x', 'isExternal' => false], $items[0]);
        $this->assertSame('フォルダの中', $items[1]['name']);
    }
}

<?php

namespace Tests\Unit\Queries\Menu;

use App\Queries\Menu\CurrentApp;
use App\Queries\Menu\VisibleMenuTree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 今のアプリの選び方と現在地のパス(docs の 設計/メニュー/表示.md 3.3.2)の、DB を読まない本体({@see CurrentApp::chosenApp()}・
 * {@see CurrentApp::currentItemPath()})。アプリはテストで置く(並びの順に a・b・c)。表示の判定で無しにする行は Feature テスト
 * (tests/Feature/Queries/Menu/CurrentAppTest.php)が確かめる。
 *
 * @phpstan-import-type VisibleGroup from VisibleMenuTree
 */
final class CurrentAppTest extends TestCase
{
    /**
     * best(0・1・2)と候補の数(1・2・3)の組み合わせ。
     *
     * @return array<string, array{string, list<array{code: string, urls: list<string>}>, string|null}>
     */
    public static function choices(): array
    {
        return [
            'best 0(当たるアイテムが無い)' => ['/x/y', [self::app('a', '/p/q'), self::app('b', '/r')], null],
            'best 0(アイテムが無い)' => ['/x/y', [self::app('a'), self::app('b')], null],
            'best 0(画面のパスが /)' => ['/', [self::app('a', '/p')], null],
            'best 1・候補 1' => ['/p/z', [self::app('a', '/p/q'), self::app('b', '/r')], 'a'],
            'best 1・候補 2(並びで先)' => ['/p/z', [self::app('a', '/p/q'), self::app('b', '/p/r')], 'a'],
            'best 1・候補 3(並びで先)' => [
                '/p/z', [self::app('a', '/p/q'), self::app('b', '/p/r'), self::app('c', '/p/s')], 'a',
            ],
            'best 2・候補 1(後ろのアプリの k が大きい)' => ['/p/q/1', [self::app('a', '/p'), self::app('b', '/p/q')], 'b'],
            'best 2・候補 2(k が小さいアプリは候補でない)' => [
                '/p/q/1', [self::app('a', '/p'), self::app('b', '/p/q'), self::app('c', '/p/q/2')], 'b',
            ],
            'best 2・候補 3' => [
                '/p/q', [self::app('a', '/p/q/1'), self::app('b', '/p/q'), self::app('c', '/p/q/2')], 'a',
            ],
            'アイテム / は区分が無く決めない' => ['/p', [self::app('a', '/'), self::app('b', '/p')], 'b'],
            'アイテム / だけなら無し' => ['/p', [self::app('a', '/')], null],
            'クエリ文字列とフラグメントを除いて比べる' => ['/p/q?tab=1', [self::app('a', '/p'), self::app('b', '/p/q#top')], 'b'],
            '空の区分を除いて比べる' => ['p//q/', [self::app('a', '/p'), self::app('b', '/p/q/')], 'b'],
            '区分の途中までの一致は数えない' => ['/pq', [self::app('a', '/p')], null],
        ];
    }

    /**
     * @param  list<array{code: string, urls: list<string>}>  $apps
     */
    #[DataProvider('choices')]
    public function test_chooses_the_app_by_the_match_length(string $path, array $apps, ?string $expected): void
    {
        $this->assertSame($expected, CurrentApp::chosenApp(CurrentApp::segmentsOf($path), $apps));
    }

    /**
     * 3.3.2 の例(/workflow → ホーム、/workflow/cases/42 → 申請一覧、/records/data/<コード>/<id> → その業務モジュール、
     * /workflow/drafts/5/edit → ホーム)と、上の階層でない画面・外部サイト・/ のアイテム。
     *
     * @return array<string, array{string, string|null}>
     */
    public static function currentPaths(): array
    {
        return [
            '/workflow → ホーム' => ['/workflow', '/workflow'],
            '/workflow/cases/42 → 申請一覧' => ['/workflow/cases/42', '/workflow/cases'],
            '/records/data/project/5 → その業務モジュール' => ['/records/data/project/5', '/records/data/project'],
            '/workflow/drafts/5/edit → ホーム(上の階層のアイテムは /workflow だけ)' => ['/workflow/drafts/5/edit', '/workflow'],
            '上の階層でない別の画面には付かない' => ['/records/data/other', null],
            '外部サイトと / のアイテムは現在地にならない' => ['/elsewhere', null],
            '引数のクエリ文字列を除いたパスで比べる' => ['/workflow/flows', '/workflow/flows'],
        ];
    }

    #[DataProvider('currentPaths')]
    public function test_the_current_item_path_is_the_deepest_one_at_or_above(string $path, ?string $expected): void
    {
        $this->assertSame($expected, CurrentApp::currentItemPath(CurrentApp::segmentsOf($path), self::group()));
    }

    /**
     * @return array{code: string, urls: list<string>}
     */
    private static function app(string $code, string ...$urls): array
    {
        return ['code' => $code, 'urls' => array_values($urls)];
    }

    /**
     * 表示判定の結果の形のグループ(フォルダの中・外部サイト・/ のアイテム・引数付きのアイテムを含む)。
     *
     * @return VisibleGroup
     */
    private static function group(): array
    {
        return [
            'code' => 'app',
            'name' => 'アプリ',
            'icon' => null,
            'description' => null,
            'entry' => ['href' => '/workflow', 'isExternal' => false],
            'children' => [
                self::item('home', '/workflow'),
                self::item('root', '/'),
                self::item('external', 'https://example.com/elsewhere', isExternal: true),
                ['code' => 'folder', 'type' => 'folder', 'name' => null, 'icon' => null, 'children' => [
                    self::item('flows', '/workflow/flows?mode=new'),
                    self::item('cases', '/workflow/cases'),
                    self::item('project', '/records/data/project'),
                ]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(string $code, string $href, bool $isExternal = false): array
    {
        return ['code' => $code, 'type' => 'item', 'name' => null,
            'icon' => null, 'href' => $href, 'isExternal' => $isExternal];
    }
}

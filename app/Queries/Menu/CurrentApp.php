<?php

namespace App\Queries\Menu;

/**
 * 今のアプリと現在地の強調のパス(規則 = docs の 設計/メニュー/表示.md 3.3.2)。画面の属するアプリを URL と全部のメニューで決めるので、
 * 全部のアイテムを表示判定をせずに 1 回の呼び出しにつき 1 回読み(カテゴリ 2 つ)、表示するかは呼び手が渡す表示判定の結果で照らす。
 * 外部サイトのアイテムは入力に入らない({@see MenuDisplayQuery::appItemUrls()})。結果を覚えない(セッションに書かない)。
 *
 * @phpstan-import-type VisibleGroup from VisibleMenuTree
 */
final readonly class CurrentApp
{
    /** 今のアプリになれるカテゴリ(この順がカテゴリをまたぐ並び = 表示.md 3.3.1) */
    private const CATEGORIES = [MenuDisplayQuery::GLOBAL_NAVIGATION_CATEGORY, MenuDisplayQuery::ADMIN_MENU_CATEGORY];

    public function __construct(private MenuDisplayQuery $menus) {}

    /**
     * 今のアプリのグループの code と現在地のパス(どちらも無ければ null)。
     *
     * @param  string  $path  画面のパス(Request::path() の形。先頭の / は問わない)
     * @param  list<VisibleGroup>  $visibleGroups  判定を求める者に表示するアプリ(ランチャーと歯車の表示判定の結果)
     * @return array{currentApp: string|null, currentItemPath: string|null}
     */
    public function of(string $path, array $visibleGroups): array
    {
        $segments = self::segmentsOf($path);
        $chosen = self::chosenApp($segments, $this->menus->appItemUrls(self::CATEGORIES));
        $group = null;

        foreach ($visibleGroups as $visibleGroup) {
            if ($visibleGroup['code'] === $chosen) {
                $group = $visibleGroup;
            }
        }

        return $group === null
            ? ['currentApp' => null, 'currentItemPath' => null]
            : ['currentApp' => $group['code'], 'currentItemPath' => self::currentItemPath($segments, $group)];
    }

    /**
     * 画面の属するアプリ(表示.md 3.3.2 のデシジョン表の「選んだアプリ」。DB を読まない本体)。当たるアイテムが無ければ null。
     *
     * @param  list<string>  $segments  画面のパスの区分
     * @param  list<array{code: string, urls: list<string>}>  $apps  すべてのアプリ(並びの順)と、そのアプリの中のパスのアイテムの URL
     */
    public static function chosenApp(array $segments, array $apps): ?string
    {
        $best = 0;
        $chosen = null;

        foreach ($apps as $app) {
            foreach ($app['urls'] as $url) {
                $length = self::matchLength($segments, self::segmentsOf($url));

                // 同点は先に見たアプリ(並びで先)のまま
                if ($length > $best) {
                    $best = $length;
                    $chosen = $app['code'];
                }
            }
        }

        return $chosen;
    }

    /**
     * 現在地のパス(表示.md 3.3.2 の現在地の強調。DB を読まない本体)。無ければ null。
     * パスは区分を / でつないだ形(先頭に /)。画面はアイテムの href のパスを同じ形にして比べる。
     *
     * @param  list<string>  $segments  画面のパスの区分
     * @param  VisibleGroup  $group  今のアプリ(表示判定の結果のグループ)
     */
    public static function currentItemPath(array $segments, array $group): ?string
    {
        $current = [];

        foreach (self::internalHrefsOf($group['children']) as $href) {
            $itemSegments = self::segmentsOf($href);
            $count = count($itemSegments);

            if ($count > count($current) && self::matchLength($segments, $itemSegments) === $count) {
                $current = $itemSegments;
            }
        }

        return $current === [] ? null : '/'.implode('/', $current);
    }

    /**
     * パスか URL の区分(クエリ文字列とフラグメントを除き、/ で区切って空の区分を除く)。
     *
     * @return list<string>
     */
    public static function segmentsOf(string $path): array
    {
        $withoutQuery = explode('#', explode('?', $path, 2)[0], 2)[0];

        return array_values(array_filter(explode('/', $withoutQuery), fn (string $segment) => $segment !== ''));
    }

    /**
     * 先頭から同じ区分が続く数。
     *
     * @param  list<string>  $segments
     * @param  list<string>  $itemSegments
     */
    private static function matchLength(array $segments, array $itemSegments): int
    {
        $length = 0;

        while (isset($segments[$length], $itemSegments[$length]) && $segments[$length] === $itemSegments[$length]) {
            $length++;
        }

        return $length;
    }

    /**
     * 木の外部サイトでないアイテムの href(前順)。
     *
     * @param  array<mixed>  $nodes
     * @return list<string>
     */
    private static function internalHrefsOf(array $nodes): array
    {
        $hrefs = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $children = $node['children'] ?? null;
            $href = $node['href'] ?? null;

            if (is_array($children)) {
                $hrefs = [...$hrefs, ...self::internalHrefsOf($children)];
            } elseif (is_string($href) && ($node['isExternal'] ?? null) === false) {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }
}

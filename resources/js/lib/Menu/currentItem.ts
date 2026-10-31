import type { MenuItemNode } from '@/types';

/** href の先頭のスキームとホスト(`https://example.com`)。アプリの中のパスは持たない */
const ORIGIN = /^[a-z][a-z0-9+.-]*:\/\/[^/?#]*/i;

/**
 * href のパスを、サーバーが渡す現在地のパス(共有プロパティ appNavigation の currentItemPath)と同じ形にする: クエリ文字列とフラグメントを除き、
 * `/` で区切って空の区分を除き、先頭に `/` を付けて `/` でつなぐ(例 `/workflow/cases?tab=a#x` → `/workflow/cases`)。
 * docs の 設計/メニュー/表示.md 3.3.2 の現在地の強調。
 */
export function pathOfHref(href: string): string {
    const path = href.replace(ORIGIN, '').split(/[?#]/)[0];
    const segments = path.split('/').filter((segment) => segment !== '');

    return `/${segments.join('/')}`;
}

/**
 * アイテムが現在地か。外部サイトのアイテムは現在地の強調をしない。currentItemPath が null(今のアプリが無い・上の階層のアイテムが無い)なら印は無い。
 */
export function isCurrentItem(
    item: Pick<MenuItemNode, 'href' | 'isExternal'>,
    currentItemPath: string | null,
): boolean {
    return (
        currentItemPath !== null &&
        !item.isExternal &&
        pathOfHref(item.href) === currentItemPath
    );
}

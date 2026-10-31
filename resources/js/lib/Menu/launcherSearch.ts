import { pathOfHref } from '@/lib/Menu/currentItem';
import { nameOrCode } from '@/lib/nameOrCode';
import type { MenuGroupNode, MenuItemNode, MenuNode } from '@/types';

/** 検索の結果の画面の行。item = 画面(アイテム)、app = 属するアプリ(行に添える名前) */
export type LauncherScreen = {
    item: MenuItemNode;
    app: MenuGroupNode;
};

/** 検索の結果。apps = 群「アプリ」、screens = 群「画面」 */
export type LauncherSearchResult = {
    apps: MenuGroupNode[];
    screens: LauncherScreen[];
};

/** 0 件のときの文(docs の 設計/メニュー/表示.md 3.3.4) */
export const LAUNCHER_EMPTY_TEXT = '該当するアプリ・画面はありません';

/** 木のアイテムを木の順に平らにする(フォルダの中身は、フォルダの並びの位置で展開する) */
function itemsOf(nodes: MenuNode[]): MenuItemNode[] {
    return nodes.flatMap((node) =>
        node.type === 'item' ? [node] : itemsOf(node.children),
    );
}

/**
 * アプリの中で同じパスを指すアイテムは、木の順で先の 1 つだけにする。2 つの分類に属す業務モジュールは同じ画面なので、
 * 同じ見た目の行を並べない(現在地の印が同じパスのアイテムを同じ画面とみなすのと同じ。docs の 設計/メニュー/表示.md 3.3.2)。
 * 外部サイトのアイテムは href そのもので比べる(パスだけでは別のサイトの同じパスを同一視するため)。
 */
function uniqueScreens(items: MenuItemNode[]): MenuItemNode[] {
    const seen = new Set<string>();

    return items.filter((item) => {
        const key = item.isExternal ? item.href : pathOfHref(item.href);

        if (seen.has(key)) {
            return false;
        }

        seen.add(key);

        return true;
    });
}

/**
 * ランチャーの検索の照合(docs の 設計/メニュー/表示.md 3.3.4 の検索)。語が空なら入れていない扱い(null)で、大文字小文字は区別しない
 * 部分一致(正規化しない)。歯車のアプリは対象に入れないので、呼び手は launcher だけを渡す。
 * 並びは、アプリは launcher の順、画面はアプリの順にアプリの中は木の順。
 */
export function searchLauncher(
    launcher: MenuGroupNode[],
    rawTerm: string,
): LauncherSearchResult | null {
    const term = rawTerm.trim().toLowerCase();

    if (term === '') {
        return null;
    }

    const includesTerm = (target: { code: string; name: string | null }) =>
        nameOrCode(target).toLowerCase().includes(term);

    return {
        apps: launcher.filter(includesTerm),
        screens: launcher.flatMap((app) => {
            const appMatches = includesTerm(app);

            // 語に当たるものを絞ってから重複を除く(先のアイテムが当たらず後ろだけが当たるときも、後ろを残す)
            const matched = itemsOf(app.children).filter(
                (item) => appMatches || includesTerm(item),
            );

            return uniqueScreens(matched).map((item) => ({ item, app }));
        }),
    };
}

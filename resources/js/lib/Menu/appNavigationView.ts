import { nameOrCode } from '@/lib/nameOrCode';
import { sidebarSections } from '@/lib/Menu/sidebarSections';
import type { SidebarSection } from '@/lib/Menu/sidebarSections';
import type {
    AppNavigation,
    BreadcrumbItem,
    MenuGroupNode,
    MenuItemNode,
} from '@/types';

/**
 * 骨格の共有プロパティ appNavigation から、画面の表示の分かれ目を決める純粋な関数(規則は docs の 設計/メニュー/表示.md 3.3)。
 * 描く部品(サイドバー・ランチャー・歯車・パンくず・全体のホーム)は、ここの結果に従って描く。
 */

/** アプリのタイル 1 つ。isCurrent = 今のアプリ(ランチャーでは「現在のアプリ」の印を付ける) */
export type AppTile = {
    app: MenuGroupNode;
    isCurrent: boolean;
};

/** 今のアプリのグループ(ランチャーのアプリか歯車のアプリ)。今のアプリが無い、または見つからなければ undefined */
function currentAppOf(nav: AppNavigation): MenuGroupNode | undefined {
    return nav.currentApp === null
        ? undefined
        : [...nav.launcher, ...nav.adminMenu].find(
              (group) => group.code === nav.currentApp,
          );
}

/** ランチャーのアプリのタイル(launcher の並びの順。docs の 設計/メニュー/表示.md 3.3.1) */
export function appTiles(nav: AppNavigation): AppTile[] {
    return nav.launcher.map((app) => ({
        app,
        isCurrent: app.code === nav.currentApp,
    }));
}

/**
 * ランチャーのパネルの中身。tiles が空ならタイルの格子を出さず、showSitemap が偽なら下端のサイトマップを出さない
 * (docs の 設計/メニュー/表示.md 3.3.4)。
 */
export function launcherPanel(nav: AppNavigation): {
    tiles: AppTile[];
    showSitemap: boolean;
} {
    return { tiles: appTiles(nav), showSitemap: nav.showSitemap };
}

/** 歯車を出すか(歯車のアプリが 1 つ以上あるときだけ。docs の 設計/メニュー/表示.md 3.3.5) */
export function showAdminMenu(nav: AppNavigation): boolean {
    return nav.adminMenu.length > 0;
}

/**
 * 全体のホームのアプリのタイル(docs の 設計/メニュー/ホーム.md 6.1)。表示するランチャーのアプリが 1 つも無ければ null(面ごと出さない)。
 */
export function homeAppTiles(nav: AppNavigation): AppTile[] | null {
    const tiles = appTiles(nav);

    return tiles.length === 0 ? null : tiles;
}

/** サイドバーの中身。app = 先頭に見出しを出す今のアプリ(無ければ null = アプリの一覧)、sections = 見出しの下の区画 */
export type SidebarView = {
    app: MenuGroupNode | null;
    sections: SidebarSection[];
};

/** アプリの一覧の見出し */
const APP_LIST_HEADING = 'アプリ';

/** アプリの一覧の 1 行(先頭の画面へのリンクとして、アイテムと同じ描き方にするため、アイテムの形にする) */
function appAsItem(app: MenuGroupNode): MenuItemNode {
    return {
        code: app.code,
        type: 'item',
        name: app.name,
        icon: app.icon,
        href: app.entry.href,
        isExternal: app.entry.isExternal,
    };
}

/**
 * サイドバーの中身。今のアプリがあれば見出しと区画、無ければ見出し「アプリ」とランチャーのアプリの一覧
 * (docs の 設計/メニュー/表示.md 3.3.3)。
 */
export function sidebarView(nav: AppNavigation): SidebarView {
    const app = currentAppOf(nav);

    if (app !== undefined) {
        return { app, sections: sidebarSections(app) };
    }

    return {
        app: null,
        sections:
            nav.launcher.length === 0
                ? []
                : [
                      {
                          key: 'apps',
                          heading: APP_LIST_HEADING,
                          nodes: nav.launcher.map(appAsItem),
                      },
                  ],
    };
}

/**
 * パンくず。今のアプリがあれば先頭にアプリの段を足し、ページの段が空ならアプリの段を最後の段(リンクにしない)にする
 * (docs の 設計/メニュー/表示.md 3.3.6)。
 */
export function appBreadcrumbs(
    nav: AppNavigation,
    pageBreadcrumbs: BreadcrumbItem[],
): BreadcrumbItem[] {
    const app = currentAppOf(nav);

    if (app === undefined) {
        return pageBreadcrumbs;
    }

    const title = nameOrCode(app);

    return pageBreadcrumbs.length === 0
        ? [{ title, href: null }]
        : [{ title, ...app.entry }, ...pageBreadcrumbs];
}

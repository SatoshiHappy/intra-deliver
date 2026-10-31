import type {
    AppNavigation,
    MenuFolderNode,
    MenuGroupNode,
    MenuItemNode,
    MenuNode,
} from '@/types';

/**
 * 単体テストが使うメニューの木の部品(名前 = コード、アイコン無し)。テストごとに違う値は overrides で渡す。
 * 名前が `.testing.ts` のファイルは `*.test.ts` からだけ import できる。
 */
export function item(
    code: string,
    href: string,
    overrides: Partial<MenuItemNode> = {},
): MenuItemNode {
    return {
        code,
        type: 'item',
        name: code,
        icon: null,
        href,
        isExternal: false,
        ...overrides,
    };
}

export function folder(
    code: string,
    children: MenuNode[],
    overrides: Partial<MenuFolderNode> = {},
): MenuFolderNode {
    return {
        code,
        type: 'folder',
        name: code,
        icon: null,
        children,
        ...overrides,
    };
}

/** グループ(アプリ)。entry は既定で先頭の画面の href `/<code>`(内部) */
export function group(
    code: string,
    children: MenuNode[],
    overrides: Partial<MenuGroupNode> = {},
): MenuGroupNode {
    return {
        code,
        name: code,
        icon: null,
        description: null,
        entry: { href: `/${code}`, isExternal: false },
        children,
        ...overrides,
    };
}

/** 骨格の共有プロパティ(既定 = アプリ無し・今のアプリ無し・サイトマップ有り) */
export function navigation(
    overrides: Partial<AppNavigation> = {},
): AppNavigation {
    return {
        launcher: [],
        adminMenu: [],
        currentApp: null,
        currentItemPath: null,
        showSitemap: true,
        ...overrides,
    };
}

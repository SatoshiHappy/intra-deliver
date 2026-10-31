import type { MenuFolderNode, MenuItemNode } from '@/types';

/** フォルダの子孫に現在地のアイテムがあるか(フォルダの初めの開き具合。docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節) */
export function folderContainsCurrent(
    folder: MenuFolderNode,
    isCurrentItem: (item: MenuItemNode) => boolean,
): boolean {
    return folder.children.some((node) =>
        node.type === 'item'
            ? isCurrentItem(node)
            : folderContainsCurrent(node, isCurrentItem),
    );
}

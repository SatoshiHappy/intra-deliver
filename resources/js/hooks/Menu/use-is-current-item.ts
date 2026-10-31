import { usePage } from '@inertiajs/react';
import { isCurrentItem } from '@/lib/Menu/currentItem';
import type { MenuItemNode } from '@/types';

/**
 * アイテムが現在地かを返す関数(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節の「現在地」)。サーバーが決めた現在地のパス
 * (共有プロパティ appNavigation の currentItemPath)と href のパスを比べる。外部サイトのアイテムは現在地の強調をしない。
 * アイテムのリンク(aria-current・data-current)と、フォルダの初めの開き具合(子孫に現在地があるか)の両方が使う。
 */
export function useIsCurrentItem(): (item: MenuItemNode) => boolean {
    const { currentItemPath } = usePage().props.appNavigation;

    return (item) => isCurrentItem(item, currentItemPath);
}

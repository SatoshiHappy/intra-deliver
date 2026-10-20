import { useCurrentUrl } from '@/hooks/Menu/use-current-url';
import type { MenuItemNode } from '@/types';

/**
 * アイテムが現在地かを返す関数(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節の「現在地」)。外部サイトのアイテムは
 * 現在地の強調をしない(isCurrentUrl はホストを見ずにパスだけを比べるため)。アイテムのリンク(aria-current・data-current)と、
 * フォルダの初めの開き具合(子孫に現在地があるか)の両方が使う。
 */
export function useIsCurrentItem(): (item: MenuItemNode) => boolean {
    const { isCurrentUrl } = useCurrentUrl();

    return (item) => !item.isExternal && isCurrentUrl(item.href);
}

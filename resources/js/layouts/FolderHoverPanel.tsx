import { MenuTree } from '@/components/Menu/MenuTree';
import { PopoverContent } from '@/components/ui/popover';
import type { FolderHover } from '@/hooks/use-folder-hover';
import { flyoutContentClass } from '@/lib/Menu/menuClasses';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { MenuFolderNode } from '@/types';

/**
 * 閉じたフォルダにマウスを乗せると右に出るパネル(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節の「フォルダのマウスオーバー」)。
 * 見出しにフォルダの表示名、続けて子(共有プロパティの子 = その木の表示判定を通ったもの)を、サイトマップのカードの中と同じ描き方(MenuTree。
 * アイテムはアイコンと表示名のリンク、子のフォルダは見出しと字下げ)で出す。焦点を移さない。画面の高さに収め、収まらなければパネルの中で縦に送る。
 * パネルのリンクを押すと閉じる。Popover の中で使い、行には PopoverAnchor を結ぶ(呼び手)。
 */
export function FolderHoverPanel({
    folder,
    hover,
}: {
    folder: MenuFolderNode;
    hover: FolderHover;
}) {
    const label = nameOrCode(folder);

    return (
        <PopoverContent
            side="right"
            align="start"
            aria-label={label}
            className={cn(
                flyoutContentClass,
                'max-h-(--radix-popover-content-available-height) overflow-y-auto',
            )}
            onOpenAutoFocus={(event) => event.preventDefault()}
            onCloseAutoFocus={(event) => event.preventDefault()}
            {...hover.panelProps}
            onClick={(event) => {
                if (
                    event.target instanceof Element &&
                    event.target.closest('a') !== null
                ) {
                    hover.close();
                }
            }}
        >
            <p className="px-2 py-1.5 text-xs text-muted-foreground">{label}</p>
            <MenuTree nodes={folder.children} />
        </PopoverContent>
    );
}

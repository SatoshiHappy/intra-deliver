import { MenuIcon } from '@/components/MenuIcon';
import { MenuItemLink } from '@/components/Menu/MenuItemLink';
import { currentItemClass } from '@/lib/Menu/menuClasses';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { MenuNode } from '@/types';

/**
 * メニューの木を、開いたままのリンクの一覧で描く(サイトマップ)。アイテムはアイコンと表示名のリンク、フォルダはアイコン付きの見出しと、
 * 字下げした中身で描き、入れ子の深さは制限しない(フォルダの中身をこの部品で描く。docs の 設計/画面の意匠/サイドバーとアイコン.md 4・5 節)。
 */
export function MenuTree({ nodes }: { nodes: MenuNode[] }) {
    return (
        <ul className="flex flex-col gap-0.5">
            {nodes.map((node) => (
                <li key={node.code}>
                    {node.type === 'item' ? (
                        <MenuItemLink
                            item={node}
                            className={cn(
                                'flex h-11 items-center gap-2 rounded-md px-2 text-sm hover:bg-accent hover:text-accent-foreground',
                                currentItemClass,
                            )}
                        />
                    ) : (
                        <>
                            <div className="flex h-11 items-center gap-2 px-2 text-sm font-medium text-muted-foreground">
                                <MenuIcon name={node.icon} />
                                <span>{nameOrCode(node)}</span>
                            </div>
                            <div className="ml-2 border-l pl-2">
                                <MenuTree nodes={node.children} />
                            </div>
                        </>
                    )}
                </li>
            ))}
        </ul>
    );
}

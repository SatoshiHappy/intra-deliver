import { MenuIcon } from '@/components/MenuIcon';
import { MenuItemLink } from '@/components/Menu/MenuItemLink';
import {
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { currentItemClass, flyoutContentClass } from '@/lib/Menu/menuClasses';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { MenuNode } from '@/types';

const rowClass = 'h-11 px-3';

/**
 * 畳んだ帯でフォルダを押すと開く一覧の中身(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節)。アイテムはアイコンと表示名のリンクの行、
 * フォルダは右に開くサブメニューで、入れ子の深さは制限しない(フォルダの中身をこの部品で描く)。現在地の行は、矢印キーでフォーカスが
 * 来たことが見えるよう、フォーカスの地を現在地の地より優先する。
 */
export function GlobalNavigationEntries({ nodes }: { nodes: MenuNode[] }) {
    return (
        <div className="flex flex-col gap-0.5">
            {nodes.map((node) =>
                node.type === 'item' ? (
                    <DropdownMenuItem
                        key={node.code}
                        asChild
                        className={cn(
                            rowClass,
                            'cursor-pointer',
                            currentItemClass,
                            'data-[current]:focus:bg-accent',
                        )}
                    >
                        <MenuItemLink item={node} />
                    </DropdownMenuItem>
                ) : (
                    <DropdownMenuSub key={node.code}>
                        <DropdownMenuSubTrigger className={rowClass}>
                            <MenuIcon name={node.icon} />
                            <span>{nameOrCode(node)}</span>
                        </DropdownMenuSubTrigger>
                        <DropdownMenuPortal>
                            <DropdownMenuSubContent
                                className={flyoutContentClass}
                            >
                                <GlobalNavigationEntries
                                    nodes={node.children}
                                />
                            </DropdownMenuSubContent>
                        </DropdownMenuPortal>
                    </DropdownMenuSub>
                ),
            )}
        </div>
    );
}

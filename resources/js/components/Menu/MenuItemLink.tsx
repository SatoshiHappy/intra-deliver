import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { MenuIcon } from '@/components/MenuIcon';
import { useIsCurrentItem } from '@/hooks/Menu/use-is-current-item';
import { nameOrCode } from '@/lib/nameOrCode';
import type { MenuItemNode } from '@/types';

// DropdownMenuItem・SidebarMenuButton の asChild(Radix の Slot)が実行時に足す属性・イベント・ref も受けて、描く要素にそのまま渡す。
// href と children は item から作るので受けない。
type Props = Omit<InertiaLinkProps, 'href' | 'children'> & {
    item: MenuItemNode;
};

/**
 * メニューのアイテムのリンク(docs の 設計/メニュー/表示.md 3.2 のリンク)。アイコン(先頭の SVG)と表示名(<span>。容器が
 * [&>span:last-child]:truncate で切り詰める)を描く。アプリの中のパスは Inertia の Link にサーバーが返す URL をそのまま渡し、
 * 現在地なら aria-current と data-current を付ける(docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節)。外部サイトは通常の <a> で描き、
 * 同じタブで開き、現在地の強調の対象にしない。
 */
export function MenuItemLink({ item, ...props }: Props) {
    const isCurrentItem = useIsCurrentItem();
    const content = (
        <>
            <MenuIcon name={item.icon} />
            <span>{nameOrCode(item)}</span>
        </>
    );

    if (item.isExternal) {
        // Inertia 固有の属性(onError など)は <a> の型と合わないが、実行時に渡るのは呼び出し元(Radix の Slot)が足す通常の属性だけ。
        return (
            <a {...(props as ComponentProps<'a'>)} href={item.href}>
                {content}
            </a>
        );
    }

    const isCurrent = isCurrentItem(item);

    return (
        <Link
            {...props}
            href={item.href}
            aria-current={isCurrent ? 'page' : undefined}
            data-current={isCurrent ? '' : undefined}
        >
            {content}
        </Link>
    );
}

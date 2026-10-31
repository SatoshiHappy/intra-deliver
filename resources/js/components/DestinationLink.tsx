import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { LinkDestination } from '@/types';

type Props = {
    destination: LinkDestination;
    className?: string;
    'aria-current'?: 'true';
    children: ReactNode;
};

/**
 * 移り先のリンク。アプリの中は Inertia の Link、外部サイトは同じタブで開く通常の a で描く
 * (リンクの規則は docs の 設計/メニュー/表示.md 3.2)。ランチャー・ホームのタイル・パンくずが使う。
 */
export function DestinationLink({ destination, children, ...props }: Props) {
    if (destination.isExternal) {
        return (
            <a {...props} href={destination.href}>
                {children}
            </a>
        );
    }

    return (
        <Link {...props} href={destination.href}>
            {children}
        </Link>
    );
}

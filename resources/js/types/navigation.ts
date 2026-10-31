import type { InertiaLinkProps } from '@inertiajs/react';

type InertiaHref = NonNullable<InertiaLinkProps['href']>;

/**
 * リンクの移り先。外部サイトは文字列の href だけ(通常の a で同じタブに開く。docs の 設計/メニュー/表示.md 3.2 のリンク)、
 * アプリの中は Inertia の href(文字列か Wayfinder の関数の戻り値)。
 */
export type LinkDestination =
    | { href: string; isExternal: boolean }
    | { href: InertiaHref; isExternal?: false };

/** パンくずの段。href が null の段はリンクにせず文字だけ(一覧を開けない利用者のパンくずなど) */
export type BreadcrumbItem = { title: string } & (
    | { href: null; isExternal?: false }
    | LinkDestination
);

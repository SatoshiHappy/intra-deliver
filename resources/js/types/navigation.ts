import type { InertiaLinkProps } from '@inertiajs/react';

export type BreadcrumbItem = {
    title: string;
    /** 移り先(null = リンクにせず文字だけ。一覧を開けない利用者のパンくずなど) */
    href: NonNullable<InertiaLinkProps['href']> | null;
};

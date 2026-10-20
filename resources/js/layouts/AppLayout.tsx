import type { ReactNode } from 'react';
import AppLayoutTemplate from '@/layouts/app/AppSidebarLayout';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    children: ReactNode;
};

export default function AppLayout({ breadcrumbs = [], children }: Props) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}

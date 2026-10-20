import type { ReactNode } from 'react';
import { AppContent } from '@/components/AppContent';
import { AppHeader } from '@/layouts/AppHeader';
import { AppShell } from '@/components/AppShell';
import { AppSidebar } from '@/layouts/AppSidebar';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
    children: ReactNode;
};

/**
 * 全幅ヘッダー + その下の畳める左サイドバー + 本文(shadcn の Sidebar のブロック sidebar-16 の形。docs の 設計/画面の意匠/骨格とヘッダー.md 2 節)。
 * ヘッダーは開閉ボタンで useSidebar() を使うので、AppShell(SidebarProvider)の内側に置く。
 */
export default function AppSidebarLayout({
    breadcrumbs = [],
    children,
}: Props) {
    return (
        <AppShell>
            <AppHeader breadcrumbs={breadcrumbs} />
            <div className="flex flex-1">
                <AppSidebar />
                <AppContent>{children}</AppContent>
            </div>
            <Toaster />
        </AppShell>
    );
}

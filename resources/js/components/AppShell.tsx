import { usePage } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';

/**
 * 画面の外枠(docs の 設計/画面の意匠/骨格とヘッダー.md 2 節)。ヘッダーの高さ --header-height(64px)の箱と、SidebarProvider で包む。
 * 開閉の記憶は部品の仕組み(クッキー sidebar_state)と、それをサーバーが返す共有プロパティ sidebarOpen(最初の描画の開き具合)。
 * サイドバーの幅 236px・帯の幅 64px(docs の 設計/画面の意匠/README.md の寸法の表)。
 */
export function AppShell({ children }: { children: ReactNode }) {
    const isOpen = usePage().props.sidebarOpen;

    return (
        <div className="[--header-height:calc(--spacing(16))]">
            <SidebarProvider
                defaultOpen={isOpen}
                className="flex flex-col"
                style={
                    {
                        '--sidebar-width': '236px',
                        '--sidebar-width-icon': '64px',
                    } as CSSProperties
                }
            >
                {children}
            </SidebarProvider>
        </div>
    );
}

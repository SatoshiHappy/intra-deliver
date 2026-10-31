import { usePage } from '@inertiajs/react';
import { GlobalNavigationTree } from '@/layouts/GlobalNavigationTree';
import { Sidebar, SidebarContent, useSidebar } from '@/components/ui/sidebar';
import { useOnNavigatingVisit } from '@/hooks/Menu/use-on-navigating-visit';
import { sidebarView } from '@/lib/Menu/appNavigationView';

/**
 * 左のサイドバー。中身は共有プロパティ appNavigation から sidebarView で決め(今のアプリの見出しと区画、無ければアプリの一覧)、
 * 画面を移る訪問が始まったら狭い幅のシートを閉じる。規則は docs の 設計/メニュー/表示.md 3.3.3、
 * 枠と帯の意匠は 設計/画面の意匠/サイドバーとアイコン.md 4 節。
 */
export function AppSidebar() {
    const view = sidebarView(usePage().props.appNavigation);
    const { setOpenMobile } = useSidebar();
    useOnNavigatingVisit(() => setOpenMobile(false));

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="top-(--header-height) h-[calc(100svh-var(--header-height))]!"
        >
            <SidebarContent className="group-data-[collapsible=icon]:[scrollbar-width:thin] group-data-[collapsible=icon]:overflow-y-auto">
                <GlobalNavigationTree view={view} />
            </SidebarContent>
        </Sidebar>
    );
}

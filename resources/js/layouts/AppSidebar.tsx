import { usePage } from '@inertiajs/react';
import { GlobalNavigationTree } from '@/layouts/GlobalNavigationTree';
import { Sidebar, SidebarContent } from '@/components/ui/sidebar';

/**
 * 左のサイドバー(docs の 設計/画面の意匠/骨格とヘッダー.md 2 節・サイドバーとアイコン.md 4 節)。全幅ヘッダーの下に置き、畳むとアイコンだけの帯になる。
 * 中身はグローバルナビ(共有プロパティ globalNavigation = サーバーの表示判定の結果。docs の 設計/メニュー/表示.md 3.2)。
 * 帯では部品が中身を overflow-hidden にするので、縦だけ動けるようにし、スクロールバーは幅を取らないものにする。
 */
export function AppSidebar() {
    const { globalNavigation } = usePage().props;

    return (
        <Sidebar
            collapsible="icon"
            variant="sidebar"
            className="top-(--header-height) h-[calc(100svh-var(--header-height))]!"
        >
            <SidebarContent className="group-data-[collapsible=icon]:[scrollbar-width:thin] group-data-[collapsible=icon]:overflow-y-auto">
                <GlobalNavigationTree groups={globalNavigation} />
            </SidebarContent>
        </Sidebar>
    );
}

import { Link } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { AppLogo } from '@/components/AppLogo';
import { Breadcrumbs } from '@/components/Breadcrumbs';
import { MasterSearch } from '@/components/Master/MasterSearch';
import { NoticeBell } from '@/components/Notice/NoticeBell';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { UserMenu } from '@/layouts/UserMenu';
import { home } from '@/routes/menu';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

/**
 * 全幅・最上段のヘッダー(docs の 設計/画面の意匠/骨格とヘッダー.md 3 節)。左から 開閉ボタン・印と製品名(ホームへのリンク)・画面名(パンくず)・
 * 共通マスタの検索欄(左に 28px 空け、残りの幅で最大 480px。中身は MasterSearch)、右端に通知の鈴(ゲストには出さない)と利用者メニュー
 * (docs の 設計/デモユーザー切替/今の利用者.md 5.7)。会社名は置かない。
 * 開閉ボタンは広い幅でサイドバーと帯を、狭い幅でシートを切り替える(部品の SidebarTrigger は印と読み上げの名前が合わないので使わない)。
 */
export function AppHeader({ breadcrumbs = [] }: Props) {
    const { open, openMobile, isMobile, toggleSidebar } = useSidebar();

    return (
        <header className="sticky top-0 z-50 flex h-(--header-height) w-full shrink-0 items-center gap-3 border-b bg-background pr-7 pl-2.5">
            <Button
                variant="ghost"
                size="icon"
                className="size-11 text-muted-foreground"
                aria-label="メニューの開閉"
                aria-expanded={isMobile ? openMobile : open}
                onClick={toggleSidebar}
            >
                <Menu className="size-5" />
            </Button>

            <Link href={home()} className="flex items-center gap-2.5">
                <AppLogo />
            </Link>

            {breadcrumbs.length > 0 && (
                <div className="ml-3 min-w-0">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            )}

            {/* 左の間隔 28px = 並びの gap-3(12px)+ ml-4(16px) */}
            <div className="ml-4 flex max-w-[480px] min-w-0 flex-1">
                <MasterSearch />
            </div>

            <div className="ml-auto flex items-center gap-1">
                <NoticeBell />
                <UserMenu />
            </div>
        </header>
    );
}

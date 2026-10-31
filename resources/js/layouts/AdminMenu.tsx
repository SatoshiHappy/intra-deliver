import { usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { Fragment } from 'react';
import { MenuIcon } from '@/components/MenuIcon';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { GlobalNavigationEntries } from '@/layouts/GlobalNavigationEntries';
import { showAdminMenu } from '@/lib/Menu/appNavigationView';
import { headerPopupButtonClass } from '@/lib/Menu/menuClasses';
import { nameOrCode } from '@/lib/nameOrCode';

/**
 * ヘッダーの歯車。歯車のアプリごとに見出しと木を出す(木は畳んだ帯の一覧と同じ GlobalNavigationEntries で描く)。
 * 歯車のアプリが無ければ場所を空けずに出さない。規則は docs の 設計/メニュー/表示.md 3.3.5、
 * 寸法と見た目は 設計/画面の意匠/骨格とヘッダー.md 3 節の 8。
 */
export function AdminMenu() {
    const nav = usePage().props.appNavigation;

    if (!showAdminMenu(nav)) {
        return null;
    }

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={headerPopupButtonClass}
                    aria-label="管理"
                >
                    <Settings className="size-5" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-74 rounded-xl p-1.5">
                {nav.adminMenu.map((app, index) => (
                    <Fragment key={app.code}>
                        {index > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuGroup>
                            <DropdownMenuLabel className="flex items-center gap-2 text-xs text-muted-foreground">
                                <MenuIcon name={app.icon} className="size-4" />
                                {nameOrCode(app)}
                            </DropdownMenuLabel>
                            <GlobalNavigationEntries nodes={app.children} />
                        </DropdownMenuGroup>
                    </Fragment>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

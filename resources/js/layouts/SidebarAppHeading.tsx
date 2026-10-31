import { MenuIcon } from '@/components/MenuIcon';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useSidebar } from '@/components/ui/sidebar';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';
import type { MenuGroupNode } from '@/types';

const HEADING_CLASS = cn(
    'mx-2 flex items-center gap-2.5 border-b px-2 pt-2 pb-3.5',
    'group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:border-b-0',
    'group-data-[collapsible=icon]:px-0',
);

/**
 * サイドバーの先頭の今のアプリの見出し(リンクにしない)。畳んだ帯では名前を読み上げ用に隠し、ツールチップで名前を出す
 * (帯のときだけ。SidebarMenuButton のツールチップと同じ条件)。意匠は docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節。
 */
export function SidebarAppHeading({ app }: { app: MenuGroupNode }) {
    const { state, isMobile } = useSidebar();
    const name = nameOrCode(app);

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <div className={HEADING_CLASS}>
                    <span className="grid size-9 shrink-0 place-items-center rounded-[10px] bg-secondary">
                        <MenuIcon name={app.icon} className="text-primary" />
                    </span>
                    <h2 className="min-w-0 truncate text-sm font-bold group-data-[collapsible=icon]:sr-only">
                        {name}
                    </h2>
                </div>
            </TooltipTrigger>
            <TooltipContent
                side="right"
                align="center"
                hidden={state !== 'collapsed' || isMobile}
            >
                {name}
            </TooltipContent>
        </Tooltip>
    );
}

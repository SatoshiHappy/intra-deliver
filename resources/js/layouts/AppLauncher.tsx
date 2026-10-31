import { usePage } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import { useState } from 'react';
import { useOnNavigatingVisit } from '@/hooks/Menu/use-on-navigating-visit';
import { LauncherPanel } from '@/components/Menu/LauncherPanel';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { headerPopupButtonClass } from '@/lib/Menu/menuClasses';

/**
 * ヘッダーのランチャー(ボタンとパネルの開閉。中身は LauncherPanel)。画面を移る訪問が始まったら閉じて語を空にする
 * (同じ画面に留まる訪問では閉じない)。開閉と焦点の規則は docs の 設計/画面の意匠/骨格とヘッダー.md 3 節の 7、
 * 出す条件は 設計/メニュー/表示.md 3.3.4。
 */
export function AppLauncher() {
    const nav = usePage().props.appNavigation;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const closeAndClear = () => {
        setOpen(false);
        setQuery('');
    };

    useOnNavigatingVisit(closeAndClear);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className={headerPopupButtonClass}
                    aria-label="アプリ"
                >
                    <LayoutGrid className="size-5" />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                sideOffset={6}
                aria-label="アプリ"
                className="grid w-[560px] gap-3.5 rounded-xl p-4"
            >
                <LauncherPanel
                    nav={nav}
                    query={query}
                    onQueryChange={setQuery}
                    onSelected={closeAndClear}
                />
            </PopoverContent>
        </Popover>
    );
}

import { router } from '@inertiajs/react';
import {
    CommandEmpty,
    CommandGroup,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { MenuIcon } from '@/components/MenuIcon';
import { LAUNCHER_EMPTY_TEXT } from '@/lib/Menu/launcherSearch';
import type {
    LauncherScreen,
    LauncherSearchResult,
} from '@/lib/Menu/launcherSearch';
import { nameOrCode } from '@/lib/nameOrCode';
import type { MenuEntry, MenuGroupNode } from '@/types';

type Props = {
    result: LauncherSearchResult;
    /** 選んだときの後始末(語を空にして閉じる)。呼び手が渡す */
    onSelected: () => void;
};

/** 行の中身(アイコン・名前・その下に添える文) */
function ResultRow({
    icon,
    name,
    note,
}: {
    icon: string | null;
    name: string;
    note: string | null;
}) {
    return (
        <>
            <span className="grid size-8 shrink-0 place-items-center rounded-[9px] bg-secondary">
                <MenuIcon name={icon} className="size-[18px] text-primary" />
            </span>
            <span className="flex min-w-0 flex-col">
                <span className="truncate font-medium">{name}</span>
                {note !== null && note !== '' && (
                    <span className="truncate text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
            </span>
        </>
    );
}

type Go = (target: MenuEntry) => void;

/** 群「アプリ」(押すと先頭の画面へ移る) */
function AppGroup({ apps, go }: { apps: MenuGroupNode[]; go: Go }) {
    return (
        <CommandGroup heading="アプリ">
            {apps.map((app) => (
                <CommandItem
                    key={app.code}
                    value={`app:${app.code}`}
                    className="gap-3"
                    onSelect={() => go(app.entry)}
                >
                    <ResultRow
                        icon={app.icon}
                        name={nameOrCode(app)}
                        note={app.description}
                    />
                </CommandItem>
            ))}
        </CommandGroup>
    );
}

/** 群「画面」(押すとその画面へ移る。添える文は属するアプリの名前) */
function ScreenGroup({ screens, go }: { screens: LauncherScreen[]; go: Go }) {
    return (
        <CommandGroup heading="画面">
            {screens.map(({ item, app }) => (
                <CommandItem
                    key={`${app.code}:${item.code}`}
                    value={`screen:${app.code}:${item.code}`}
                    className="gap-3"
                    onSelect={() => go(item)}
                >
                    <ResultRow
                        icon={item.icon}
                        name={nameOrCode(item)}
                        note={nameOrCode(app)}
                    />
                </CommandItem>
            ))}
        </CommandGroup>
    );
}

/**
 * ランチャーの検索の結果(群「アプリ」「画面」と 0 件の文)。選ぶと後始末を呼んでから移る(外部サイトは同じタブで開く)。
 * 群と 0 件の文の規則は docs の 設計/メニュー/表示.md 3.3.4、外部サイトのリンクの規則は同 3.2。
 * 項目の value は app:<code>・screen:<アプリ>:<code>(同じ表示の項目もキー操作で区別できるように)。
 */
export function LauncherSearchResults({ result, onSelected }: Props) {
    const go: Go = ({ href, isExternal }) => {
        onSelected();

        if (isExternal) {
            window.location.assign(href);
        } else {
            router.visit(href);
        }
    };
    const { apps, screens } = result;

    return (
        <CommandList>
            {apps.length === 0 && screens.length === 0 && (
                <CommandEmpty>{LAUNCHER_EMPTY_TEXT}</CommandEmpty>
            )}
            {apps.length > 0 && <AppGroup apps={apps} go={go} />}
            {screens.length > 0 && <ScreenGroup screens={screens} go={go} />}
        </CommandList>
    );
}

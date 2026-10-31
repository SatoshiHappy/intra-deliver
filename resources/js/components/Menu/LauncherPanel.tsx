import { Link } from '@inertiajs/react';
import { Command as CommandPrimitive } from 'cmdk';
import { MapIcon, Search } from 'lucide-react';
import { LauncherSearchResults } from '@/components/Menu/LauncherSearchResults';
import { LauncherTile } from '@/components/Menu/LauncherTile';
import { Command } from '@/components/ui/command';
import { launcherPanel } from '@/lib/Menu/appNavigationView';
import { searchLauncher } from '@/lib/Menu/launcherSearch';
import { sitemap } from '@/routes/menu';
import { cn } from '@/lib/utils';
import type { AppNavigation } from '@/types';

/** 検索欄の枠(ヘッダーの検索欄と同じ見た目) */
const SEARCH_BOX_CLASS = cn(
    'flex h-10 items-center gap-2 rounded-md border border-input bg-background px-3 shadow-xs',
    'transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50',
    'dark:bg-input/30',
);

const SEARCH_INPUT_CLASS = cn(
    'h-full w-full min-w-0 bg-transparent text-sm outline-hidden',
    'placeholder:text-muted-foreground',
);

/** 検索欄のプレースホルダーと読み上げの名前 */
const SEARCH_LABEL = 'アプリと画面を検索';

type Props = {
    nav: AppNavigation;
    query: string;
    onQueryChange: (query: string) => void;
    /** 検索の結果を選んだときの後始末(語を空にして閉じる) */
    onSelected: () => void;
};

/** 下端のサイトマップのリンク */
function SitemapLink() {
    return (
        <div className="border-t pt-3">
            <Link href={sitemap()} className="flex h-9 items-center gap-2.5">
                <MapIcon className="size-[18px] text-primary" />
                <span className="font-medium text-primary">サイトマップ</span>
                <span className="text-xs text-muted-foreground">
                    すべての画面を一覧で表示します。
                </span>
            </Link>
        </div>
    );
}

/**
 * ランチャーのパネルの中身(検索欄 → タイル or 検索の結果 → サイトマップ)。Command は検索欄と結果だけを包む
 * (タイルとサイトマップのリンクを Enter で押せるように。cmdk は Enter を止める)。
 * 規則は docs の 設計/メニュー/表示.md 3.3.4、寸法は 設計/画面の意匠/骨格とヘッダー.md 3 節の 7。
 */
export function LauncherPanel({
    nav,
    query,
    onQueryChange,
    onSelected,
}: Props) {
    const { tiles, showSitemap } = launcherPanel(nav);
    const result = searchLauncher(nav.launcher, query);

    return (
        <>
            <Command
                shouldFilter={false}
                label={SEARCH_LABEL}
                className="h-auto overflow-visible bg-transparent"
            >
                <div className={SEARCH_BOX_CLASS}>
                    <Search className="size-5 shrink-0 text-muted-foreground" />
                    <CommandPrimitive.Input
                        value={query}
                        onValueChange={onQueryChange}
                        placeholder={SEARCH_LABEL}
                        className={SEARCH_INPUT_CLASS}
                    />
                </div>
                {result !== null && (
                    <LauncherSearchResults
                        result={result}
                        onSelected={onSelected}
                    />
                )}
            </Command>
            {result === null && tiles.length > 0 && (
                <ul className="grid grid-cols-3 gap-2">
                    {tiles.map((tile) => (
                        <li key={tile.app.code} className="grid">
                            <LauncherTile tile={tile} />
                        </li>
                    ))}
                </ul>
            )}
            {showSitemap && <SitemapLink />}
        </>
    );
}

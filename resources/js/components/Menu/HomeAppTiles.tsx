import { DestinationLink } from '@/components/DestinationLink';
import { HomePanel } from '@/components/Menu/HomePanel';
import { MenuIcon } from '@/components/MenuIcon';
import type { AppTile } from '@/lib/Menu/appNavigationView';
import { nameOrCode } from '@/lib/nameOrCode';
import { cn } from '@/lib/utils';

const TILE_CLASS = cn(
    'flex min-h-[76px] items-center gap-3.5 rounded-md border bg-background px-3.5 py-3',
    'hover:border-primary hover:bg-accent',
);

/**
 * 全体のホームのアプリのタイル。ランチャーのアプリを並びの順に出し、押すと先頭の画面へ移る。
 * 面と意匠は docs の 設計/メニュー/ホーム.md 6.3、どのアプリを出すかは 設計/メニュー/表示.md 3.3.1。
 */
export function HomeAppTiles({ tiles }: { tiles: AppTile[] }) {
    return (
        <HomePanel code="home-apps" heading="アプリ" more={null}>
            <ul className="grid grid-cols-3 gap-3">
                {tiles.map(({ app }) => (
                    <li key={app.code} className="grid">
                        <DestinationLink
                            destination={app.entry}
                            className={TILE_CLASS}
                        >
                            <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-secondary">
                                <MenuIcon
                                    name={app.icon}
                                    className="size-[22px] text-primary"
                                />
                            </span>
                            <span className="flex min-w-0 flex-col">
                                <span className="truncate text-sm font-bold">
                                    {nameOrCode(app)}
                                </span>
                                {app.description !== null &&
                                    app.description !== '' && (
                                        <span className="text-xs text-muted-foreground">
                                            {app.description}
                                        </span>
                                    )}
                            </span>
                        </DestinationLink>
                    </li>
                ))}
            </ul>
        </HomePanel>
    );
}

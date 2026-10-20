import { Link } from '@inertiajs/react';
import { HomePanel } from '@/components/Menu/HomePanel';
import { MenuIcon } from '@/components/MenuIcon';
import type { HomeTilesSection } from '@/types';

const TILE_CLASS =
    'grid h-full min-h-[84px] place-items-center content-center gap-1.5 rounded-md border bg-muted px-1.5 py-2.5 text-center text-sm font-medium hover:border-transparent hover:bg-secondary hover:text-primary';

/**
 * 操作のタイルの節(docs の 設計/メニュー/ホーム.md 6.3。幅 96px 以上で詰める格子)。リンクはサイドバーのアイテムと同じ
 * (アプリの中のパスは Inertia の Link、外部サイトは同じタブで開く通常の a。docs の 設計/メニュー/表示.md 3.2)。
 */
export function HomeTiles({ section }: { section: HomeTilesSection }) {
    return (
        <HomePanel code={section.code} heading={section.heading} more={null}>
            <ul className="grid grid-cols-[repeat(auto-fit,minmax(96px,1fr))] gap-2.5">
                {section.items.map((item) => {
                    const content = (
                        <>
                            <MenuIcon
                                name={item.icon}
                                className="size-[22px] text-primary"
                            />
                            {item.label}
                        </>
                    );

                    return (
                        <li key={item.code}>
                            {item.isExternal ? (
                                <a href={item.url} className={TILE_CLASS}>
                                    {content}
                                </a>
                            ) : (
                                <Link href={item.url} className={TILE_CLASS}>
                                    {content}
                                </Link>
                            )}
                        </li>
                    );
                })}
            </ul>
        </HomePanel>
    );
}

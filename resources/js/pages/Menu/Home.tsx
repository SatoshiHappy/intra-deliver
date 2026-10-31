import { Head, usePage } from '@inertiajs/react';
import { HomeAppTiles } from '@/components/Menu/HomeAppTiles';
import { HomeCounters } from '@/components/Menu/HomeCounters';
import { HomeHero } from '@/components/Menu/HomeHero';
import { HomePeople } from '@/components/Menu/HomePeople';
import { HomeRoutes } from '@/components/Menu/HomeRoutes';
import { HomeTable } from '@/components/Menu/HomeTable';
import { HomeTiles } from '@/components/Menu/HomeTiles';
import { homeAppTiles } from '@/lib/Menu/appNavigationView';
import { homeTitle } from '@/lib/Menu/homeHeading';
import type { HomeApp } from '@/lib/Menu/homeHeading';
import { home } from '@/routes/menu';
import type { HomeLeadSection, HomeSection } from '@/types';

type Props = {
    /** 基準日 d(YYYY-MM-DD。サーバーの AccessContext の date) */
    today: string;
    sections: HomeSection[];
    /** null = 全体のホーム。name = アプリのホームの入口が渡すアプリの名前(docs の 設計/メニュー/ホーム.md 6.1・6.7) */
    app: HomeApp | null;
};

/**
 * 節 1 つを、種類ごとの部品で描く(docs の 設計/メニュー/ホーム.md 6.3)。lead はあいさつ欄にだけ置くので、ここでは描かない。
 */
function HomeSectionView({ section }: { section: HomeSection }) {
    switch (section.kind) {
        case 'counters':
            return <HomeCounters section={section} />;
        case 'table':
            return <HomeTable section={section} />;
        case 'tiles':
            return <HomeTiles section={section} />;
        case 'routes':
            return <HomeRoutes section={section} />;
        case 'people':
            return <HomePeople section={section} />;
        default:
            return null;
    }
}

/** 2 列(main 2 : side 1)。節のある列だけを出し、1 列だけなら全幅にする。どちらにも節が無ければ何も出さない(docs の 設計/メニュー/ホーム.md 6.1) */
function HomeColumns({ columns }: { columns: HomeSection[][] }) {
    if (columns.length === 0) {
        return null;
    }

    return (
        <div
            className={
                columns.length === 2
                    ? 'grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)] items-start gap-5'
                    : 'grid gap-5'
            }
        >
            {columns.map((column) => (
                <div key={column[0].area} className="grid min-w-0 gap-5">
                    {column.map((section) => (
                        <HomeSectionView key={section.code} section={section} />
                    ))}
                </div>
            ))}
        </div>
    );
}

/**
 * ホーム(docs の 設計/メニュー/ホーム.md 6.1): あいさつ欄(h1・日付・種類 lead の節)→ 上段(top)→ アプリのタイル(全体のホームだけ)→
 * 2 列(main 2 : side 1)。アプリのホーム(app がある)は、題と h1 がアプリの名前で、飾りの葉とアプリのタイルを出さない(6.7)。
 * 節の中身はほかのモジュールが返し、Menu が種類ごとに描く。節が無い置き場は詰める(main と side の両方に無ければ 2 列を出さず、
 * 片方だけなら節のある列を全幅の 1 列で出す)。ゲストは今の利用者(共有プロパティ account の user)が null のこと。
 */
export default function Home({ today, sections, app }: Props) {
    const { user } = usePage().props.account;
    const tiles = homeAppTiles(usePage().props.appNavigation);
    const leads = sections.filter(
        (section): section is HomeLeadSection => section.kind === 'lead',
    );
    const inArea = (area: 'top' | 'main' | 'side') =>
        sections.filter(
            (section) => section.kind !== 'lead' && section.area === area,
        );
    const top = inArea('top');
    const columns = [inArea('main'), inArea('side')].filter(
        (column) => column.length > 0,
    );

    return (
        <>
            <Head title={homeTitle(app)} />

            <div className="flex h-full flex-1 flex-col gap-5 p-4">
                <HomeHero today={today} app={app} user={user} leads={leads} />

                {top.length > 0 && (
                    <div className="grid gap-5">
                        {top.map((section) => (
                            <HomeSectionView
                                key={section.code}
                                section={section}
                            />
                        ))}
                    </div>
                )}

                {app === null && tiles !== null && (
                    <HomeAppTiles tiles={tiles} />
                )}

                <HomeColumns columns={columns} />
            </div>
        </>
    );
}

Home.layout = {
    breadcrumbs: [
        {
            title: 'ホーム',
            href: home(),
        },
    ],
};

import { Head, usePage } from '@inertiajs/react';
import { HomeCounters } from '@/components/Menu/HomeCounters';
import { HomeLead } from '@/components/Menu/HomeLead';
import { HomeLeaves } from '@/components/Menu/HomeLeaves';
import { HomePeople } from '@/components/Menu/HomePeople';
import { HomeRoutes } from '@/components/Menu/HomeRoutes';
import { HomeTable } from '@/components/Menu/HomeTable';
import { HomeTiles } from '@/components/Menu/HomeTiles';
import { nameOrCode } from '@/lib/nameOrCode';
import { home } from '@/routes/menu';
import type { HomeLeadSection, HomeSection } from '@/types';

type Props = {
    /** 基準日 d(YYYY-MM-DD。サーバーの AccessContext の date) */
    today: string;
    sections: HomeSection[];
};

/**
 * 日付の書式(`2026年10月15日(木)`)。today を UTC の 0 時として UTC のまま書式にし、暦の日付をずらさない。
 * サーバーの基準日と同じ日を出すため、画面の時計は使わない(docs の 設計/メニュー/ホーム.md 6.1)。
 */
const dateFormat = new Intl.DateTimeFormat('ja-JP', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    weekday: 'short',
    timeZone: 'UTC',
});

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

/**
 * ホーム(docs の 設計/メニュー/ホーム.md 6.1): あいさつ欄(h1・日付・種類 lead の節)→ 上段(top)→ 2 列(main 2 : side 1)。
 * 節の中身はほかのモジュールが返し、Menu が種類ごとに描く。節が無い置き場は詰める(main と side の両方に無ければ 2 列を出さず、
 * 片方だけなら節のある列を全幅の 1 列で出す)。ゲストは今の利用者(共有プロパティ account の user)が null のこと。
 */
export default function Home({ today, sections }: Props) {
    const { user } = usePage().props.account;
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
            <Head title="ホーム" />

            <div className="flex h-full flex-1 flex-col gap-5 p-4">
                <section
                    aria-label="ごあいさつ"
                    className="relative grid min-h-[168px] grid-cols-[minmax(0,1fr)_auto] items-center gap-6 overflow-hidden rounded-xl border bg-[linear-gradient(100deg,var(--secondary)_0%,var(--card)_58%,var(--secondary)_100%)] px-[34px] py-[30px]"
                >
                    <HomeLeaves />
                    <div className="relative z-10">
                        <h1 className="mb-1.5 text-2xl font-bold tracking-[0.02em] text-balance">
                            {user === null ? (
                                <>
                                    ようこそ、
                                    <span className="text-primary">
                                        イントラデリバー
                                    </span>
                                    へ
                                </>
                            ) : (
                                <>
                                    こんにちは、
                                    <span className="text-primary">
                                        {nameOrCode(user)}さん
                                    </span>
                                </>
                            )}
                        </h1>
                        {leads.map((section) => (
                            <HomeLead key={section.code} section={section} />
                        ))}
                    </div>
                    <div className="relative z-10 text-right">
                        <span className="font-num text-sm font-medium">
                            {dateFormat.format(new Date(today))}
                        </span>
                    </div>
                </section>

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

                {columns.length > 0 && (
                    <div
                        className={
                            columns.length === 2
                                ? 'grid grid-cols-[minmax(0,2fr)_minmax(0,1fr)] items-start gap-5'
                                : 'grid gap-5'
                        }
                    >
                        {columns.map((column) => (
                            <div
                                key={column[0].area}
                                className="grid min-w-0 gap-5"
                            >
                                {column.map((section) => (
                                    <HomeSectionView
                                        key={section.code}
                                        section={section}
                                    />
                                ))}
                            </div>
                        ))}
                    </div>
                )}
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

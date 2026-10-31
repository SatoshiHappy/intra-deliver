import { HomeLead } from '@/components/Menu/HomeLead';
import { HomeLeaves } from '@/components/Menu/HomeLeaves';
import { homeHeading } from '@/lib/Menu/homeHeading';
import type { HomeApp } from '@/lib/Menu/homeHeading';
import { cn } from '@/lib/utils';
import type { HomeLeadSection } from '@/types';

type Props = {
    /** 基準日 d(YYYY-MM-DD。サーバーの AccessContext の date) */
    today: string;
    app: HomeApp | null;
    user: { code: string; name: string | null } | null;
    /** あいさつ欄に置く種類 lead の節(登録の順) */
    leads: HomeLeadSection[];
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

/** あいさつ欄の面(地はグラデーション 100deg、--secondary → 58% で --card → --secondary) */
const HERO_CLASS = cn(
    'relative grid min-h-[168px] grid-cols-[minmax(0,1fr)_auto] items-center gap-6 overflow-hidden rounded-xl border',
    'bg-[linear-gradient(100deg,var(--secondary)_0%,var(--card)_58%,var(--secondary)_100%)] px-[34px] py-[30px]',
);

/** 見出しの中身(あいさつの語。強調は text-primary) */
function HeadingText({ app, user }: Pick<Props, 'app' | 'user'>) {
    const heading = homeHeading(app, user);

    switch (heading.kind) {
        case 'app':
            return heading.name;
        case 'guest':
            return (
                <>
                    ようこそ、
                    <span className="text-primary">イントラデリバー</span>へ
                </>
            );
        case 'user':
            return (
                <>
                    こんにちは、
                    <span className="text-primary">{heading.name}さん</span>
                </>
            );
    }
}

/**
 * ホームのあいさつ欄(docs の 設計/メニュー/ホーム.md 6.1・6.3): 見出し(h1。ページの唯一の h1)・種類 lead の節・右に日付。
 * アプリのホームは飾りの葉(6.4)を出さない。
 */
export function HomeHero({ today, app, user, leads }: Props) {
    return (
        <section aria-label="ごあいさつ" className={HERO_CLASS}>
            {app === null && <HomeLeaves />}
            <div className="relative z-10">
                <h1 className="mb-1.5 text-2xl font-bold tracking-[0.02em] text-balance">
                    <HeadingText app={app} user={user} />
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
    );
}

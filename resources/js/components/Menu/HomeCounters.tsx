import { Link } from '@inertiajs/react';
import { MenuIcon } from '@/components/MenuIcon';
import type { HomeCountersSection } from '@/types';

/**
 * 件数カードの並び(docs の 設計/メニュー/ホーム.md 6.3。4 列の格子)。カード全体がリンク。見出しは描かず、区画の読み上げの名前にする。
 * 内訳の行は空でも高さを保つ(0 件のカードで数字の位置が変わらないように)。
 */
export function HomeCounters({ section }: { section: HomeCountersSection }) {
    return (
        <section
            id={section.code}
            aria-label={section.heading}
            className="grid grid-cols-4 gap-4"
        >
            {section.items.map((item) => (
                <Link
                    key={item.code}
                    href={item.url}
                    className="flex min-w-0 items-start gap-3 rounded-xl border bg-card px-3.5 py-[18px] text-card-foreground hover:border-primary"
                >
                    <span className="mt-[11px] flex size-12 shrink-0 items-center justify-center rounded-full bg-secondary text-primary">
                        <MenuIcon
                            name={item.icon}
                            className="size-[22px] text-primary"
                        />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="block text-xs whitespace-nowrap text-muted-foreground">
                            {item.label}
                        </span>
                        <span className="block leading-[1.15]">
                            <span className="font-num text-2xl font-semibold tabular-nums">
                                {item.count}
                            </span>
                            <span className="ml-1 text-sm font-medium">件</span>
                        </span>
                        <span className="block min-h-6 text-xs leading-6 text-muted-foreground">
                            {item.note}
                        </span>
                    </span>
                </Link>
            ))}
        </section>
    );
}

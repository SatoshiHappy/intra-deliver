import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';

type Props = {
    /** 節の code(ページ内リンクの行き先。一番外の要素の id) */
    code: string;
    heading: string;
    more: { label: string; url: string } | null;
    children: ReactNode;
};

/**
 * 表・タイル・経路の面(docs の 設計/メニュー/ホーム.md 6.3)。頭の行に h2 と、右端に `more` のリンク(あるときだけ)を出し、中身を下に置く。
 * `more` のリンクには、読み上げ用に `(<heading>)` を添える。
 */
export function HomePanel({ code, heading, more, children }: Props) {
    return (
        <section
            id={code}
            className="min-w-0 rounded-xl border bg-card p-5 text-card-foreground"
        >
            <div className="mb-3.5 flex items-center gap-3">
                <h2 className="text-base font-bold">{heading}</h2>
                {more !== null && (
                    <Link
                        href={more.url}
                        className="ml-auto inline-flex items-center gap-0.5 text-sm font-medium text-primary hover:underline"
                    >
                        {more.label}
                        <span className="sr-only">({heading})</span>
                        <ChevronRight className="size-4" />
                    </Link>
                )}
            </div>
            {children}
        </section>
    );
}

import type { ReactNode } from 'react';
import { HeadingHelp } from '@/components/HeadingHelp';
import { cn } from '@/lib/utils';

/**
 * カードの見出し(番号 + 名前。番号は読み上げない)と、横の「?」(見出しの要素の外。HeadingHelp)。
 */
function StepCardHeading({
    id,
    number,
    heading,
    help,
}: {
    id: string;
    number: number;
    heading: string;
    help: ReactNode;
}) {
    return (
        <div className="flex items-center gap-2">
            <h3
                id={id}
                className="flex items-center gap-2 text-sm font-semibold"
            >
                <span
                    aria-hidden="true"
                    className="inline-flex size-5 items-center justify-center rounded-full bg-secondary text-xs"
                >
                    {number}
                </span>
                {heading}
            </h3>
            {help !== null && <HeadingHelp label={heading}>{help}</HeadingHelp>}
        </div>
    );
}

/** 見出しの番号の丸の幅と間(size-5 + gap-2)。カードの中身はこの分だけ字下げして、見出しの文字の頭にそろえる */
const CONTENT_INDENT = 'pl-7';

/**
 * ステップの設定のカード 1 つ(番号つき太字の見出し + 横の「?」+ 見出しの下の入力欄。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節)。見出しの説明(固定の文。カード 1 は指定の方法で変わる文)は
 * 見出しの横の「?」の Tooltip に入れる(設計/画面の意匠/README.md の方針 9(d))。help = null なら「?」を出さない。
 * 中身は見出しの文字の頭にそろえて字下げする(どのカードも同じ = ユーザーの決定 2026-11-01)。
 */
export function StepCard({
    id,
    number,
    heading,
    help,
    children,
}: {
    /** 見出しの id(カードの名前) */
    id: string;
    number: number;
    heading: string;
    help: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={id}
            className="flex flex-col gap-3 border-t pt-4 first:border-t-0 first:pt-0"
        >
            <StepCardHeading
                id={id}
                number={number}
                heading={heading}
                help={help}
            />
            <div className={cn('flex flex-col gap-3', CONTENT_INDENT)}>
                {children}
            </div>
        </section>
    );
}

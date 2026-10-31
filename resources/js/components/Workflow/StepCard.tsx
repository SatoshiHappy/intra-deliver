import type { ReactNode } from 'react';
import { FieldHelp } from '@/components/FormBlock';

/**
 * カードの見出し(番号 + 名前。番号は読み上げない)と、横の「?」。
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
            {help !== undefined && (
                <FieldHelp id={id} label={heading}>
                    {help}
                </FieldHelp>
            )}
        </div>
    );
}

/**
 * ステップの設定のカード 1 つ(番号つき太字の見出し + 見出しの下の 1 行 + 入力欄。docs の
 * 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節)。help は見出しの横の「?」(説明は Tooltip)、aside は見出しの右。
 */
export function StepCard({
    id,
    number,
    heading,
    line,
    help,
    aside,
    children,
}: {
    /** 見出しの id(カードの名前。「?」の説明の id もここから作る) */
    id: string;
    number: number;
    heading: string;
    line: string | null;
    help?: ReactNode;
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={id}
            className="flex flex-col gap-3 border-t pt-4 first:border-t-0 first:pt-0"
        >
            <div className="flex items-center justify-between gap-2">
                <StepCardHeading
                    id={id}
                    number={number}
                    heading={heading}
                    help={help}
                />
                {aside}
            </div>
            {line !== null && (
                <p className="text-xs text-muted-foreground">{line}</p>
            )}
            {children}
        </section>
    );
}

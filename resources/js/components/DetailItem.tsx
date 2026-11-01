import type { ComponentProps, ReactNode } from 'react';
import { TipButton } from '@/components/TipButton';
import { cn } from '@/lib/utils';

/** 名前の横の注(name = 項目の名前。読み上げの名前「<name>の注」に使う。text = 注の文) */
type DetailNote = { name: string; text: string };

/**
 * 詳細の「名前の下に値」の 1 項目(dl の中に置く)。描き方を 1 か所にして、使う画面どうしの見た目を揃える
 * (docs の 設計/画面の意匠/README.md の方針 9(e))。注は名前の横の「i」({@link TipButton} の注)で、文の写しを値の後ろの
 * 見えない dd に置く(ブラウズの読み上げで名前 → 値 → 注の順に届く。Tooltip が開いている間は、ボタンの説明にも文が入る)。
 */
export function DetailItem({
    name,
    note,
    className,
    valueClassName,
    valueProps,
    children,
}: {
    name: ReactNode;
    note?: DetailNote;
    className?: string;
    valueClassName?: string;
    valueProps?: Pick<
        ComponentProps<'dd'>,
        'aria-invalid' | 'aria-describedby'
    >;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1 border-b pb-3',
                className,
            )}
        >
            <dt className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                {name}
                {note !== undefined && (
                    <TipButton kind="note" name={note.name}>
                        {note.text}
                    </TipButton>
                )}
            </dt>
            <dd {...valueProps} className={cn('min-w-0', valueClassName)}>
                {children}
            </dd>
            {note !== undefined && <dd className="sr-only">{note.text}</dd>}
        </div>
    );
}

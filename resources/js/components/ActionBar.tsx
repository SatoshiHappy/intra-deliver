import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 保存・処理のボタンの帯(docs の 設計/画面の意匠/README.md の方針 14・11)。raised = 帯の下に中身が続くとき。
 * 下の角は欄の角丸から枠の 1px を引いた値(欄の枠の内側に収めるため)。上向きの影はトークンに無いので、shadow-md の値を上向きにした任意値。
 */
export function ActionBar({
    raised,
    className,
    children,
}: {
    raised: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            data-raised={raised || undefined}
            className={cn(
                'flex shrink-0 items-center justify-end gap-2 rounded-b-[11px] border-t bg-card px-5 py-4',
                raised &&
                    'shadow-[0_-4px_6px_-1px_rgb(0_0_0/0.1),0_-2px_4px_-2px_rgb(0_0_0/0.1)]',
                className,
            )}
        >
            {children}
        </div>
    );
}

import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 今は使えない値を空にした知らせの注意(docs の 設計/ワークフロー/申請の周辺/画面.md の 5 節の「空にした項目の知らせ」)。
 * 注意の色(差し戻しの札と同じ)と ⚠ で、説明の文と見分ける。
 */
export function ClearedNotice({ children }: { children: ReactNode }) {
    return (
        <p
            className={cn(
                'inline-flex w-fit items-center gap-1.5 rounded-md px-3 py-1.5 text-sm',
                'bg-status-returned text-status-returned-foreground',
            )}
        >
            <TriangleAlert aria-hidden="true" className="size-4 shrink-0" />
            {children}
        </p>
    );
}

/** 空にした欄の枠(注意の色) */
export const CLEARED_FRAME_CLASS =
    'rounded-md ring-2 ring-status-returned-foreground/70';

/**
 * 空にした欄の理由の 1 行(欄の下・明細の表の上。注意の色と ⚠)。
 */
export function ClearedReasonLine({
    id,
    children,
    className,
}: {
    id?: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <p
            id={id}
            className={cn(
                'flex items-center gap-1 text-xs text-status-returned-foreground',
                className,
            )}
        >
            <TriangleAlert aria-hidden="true" className="size-3.5 shrink-0" />
            {children}
        </p>
    );
}

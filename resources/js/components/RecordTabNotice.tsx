import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 詳細タブの先頭の 1 行(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 4 節)。tone = 注意(returned の色)か
 * 案内(薄い面)。
 */
export function RecordTabNotice({
    tone = 'info',
    children,
}: {
    tone?: 'info' | 'warning';
    children: ReactNode;
}) {
    return (
        <p
            role="note"
            className={cn(
                'rounded-md px-3 py-2 text-sm',
                tone === 'warning'
                    ? 'bg-status-returned text-status-returned-foreground'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            {children}
        </p>
    );
}

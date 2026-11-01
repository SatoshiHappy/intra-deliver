import { Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * 詳細タブの先頭の 1 行(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 4 節)。tone = 注意(returned の色)か
 * 案内(薄い面)。locked = 伏せた中身の位置の 1 行(鍵の印)。
 */
export function RecordTabNotice({
    tone = 'info',
    locked = false,
    children,
}: {
    tone?: 'info' | 'warning';
    locked?: boolean;
    children: ReactNode;
}) {
    return (
        <p
            role="note"
            className={cn(
                'rounded-md px-3 py-2 text-sm',
                locked && 'flex items-start gap-2',
                tone === 'warning'
                    ? 'bg-status-returned text-status-returned-foreground'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            {locked && <Lock aria-hidden className="mt-0.5 size-4 shrink-0" />}
            {children}
        </p>
    );
}

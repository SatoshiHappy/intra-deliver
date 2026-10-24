import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { StatusTone } from '@/types';

const TONE_CLASSES: Record<StatusTone, string> = {
    pending: 'bg-status-pending text-status-pending-foreground',
    returned: 'bg-status-returned text-status-returned-foreground',
    settled: 'bg-status-settled text-status-settled-foreground',
    rejected: 'bg-status-rejected text-status-rejected-foreground',
};

/**
 * 状態の札(Badge の地と字を状態色にしたもの。docs の 設計/画面の意匠/トークンとダーク.md 1.2、設計/ワークフロー/画面/ホームと意匠とE2E.md 8 節)。
 * 語を必ず出し、色だけで伝えない(結果には ✓ / ×、保留中には一時停止の印)。ワークフローの画面とホームの表の節の 2 つのモジュールが使うため直下に置く
 * (docs の 設計/コード構成/README.md 5 節)。
 */
export function StatusBadge({
    label,
    tone,
    mark = null,
}: {
    label: string;
    tone: StatusTone;
    mark?: ReactNode;
}) {
    return (
        <Badge className={cn('border-transparent', TONE_CLASSES[tone])}>
            {mark !== null && <span aria-hidden="true">{mark}</span>}
            {label}
        </Badge>
    );
}

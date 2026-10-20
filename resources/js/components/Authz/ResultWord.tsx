import { Check, X } from 'lucide-react';
import { RESULT_LABELS } from '@/lib/Authz/labels';
import { cn } from '@/lib/utils';

const STYLES = {
    permit: { Icon: Check, className: 'text-primary' },
    deny: { Icon: X, className: 'text-destructive' },
    block: { Icon: null, className: 'text-status-rejected-foreground' },
} as const;

/**
 * 判定の結果の語(許可 = Check + 語、禁止 = X + 語、閉塞 = 語)。
 */
export function ResultWord({
    result,
}: {
    result: 'permit' | 'deny' | 'block';
}) {
    const { Icon, className } = STYLES[result];

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 font-medium',
                className,
            )}
        >
            {Icon !== null && <Icon className="size-4" aria-hidden="true" />}
            {RESULT_LABELS[result]}
        </span>
    );
}

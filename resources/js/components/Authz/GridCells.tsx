import type { ComponentProps } from 'react';
import { TableCell, TableHead } from '@/components/ui/table';
import { cn } from '@/lib/utils';

/** 列の束の間隔(edge = 先頭の左、between = カテゴリの間、inner = 同じカテゴリの対象者の間) */
const GAP_WIDTH = {
    edge: 'w-5 min-w-5',
    between: 'w-8 min-w-8',
    inner: 'w-3 min-w-3',
};

export type GapKind = keyof typeof GAP_WIDTH;

/**
 * 本体のセル。行の区切りは横線だけ(最後の行は線なし)。band = 該当の帯。
 */
export function GridCell({
    band = false,
    className,
    ...props
}: ComponentProps<typeof TableCell> & { band?: boolean }) {
    return (
        <TableCell
            className={cn(
                'h-12 border-b group-last/row:border-b-0',
                band && 'bg-secondary',
                className,
            )}
            {...props}
        />
    );
}

/**
 * 見出しのセル。止まる見出しの地は不透明(band = 該当の帯)。
 */
export function GridHead({
    band = false,
    className,
    ...props
}: ComponentProps<typeof TableHead> & { band?: boolean }) {
    return (
        <TableHead
            className={cn('bg-card', band && 'bg-secondary', className)}
            {...props}
        />
    );
}

/**
 * 列の束の間の空き(本体)。
 */
export function GapCell({ kind }: { kind: GapKind }) {
    return (
        <TableCell
            aria-hidden="true"
            className={cn(
                'h-12 border-b p-0 group-last/row:border-b-0',
                GAP_WIDTH[kind],
            )}
        />
    );
}

/**
 * 列の束の間の空き(見出し)。bordered = 最後の見出しの段の下線。
 */
export function GapHead({
    kind,
    bordered = false,
}: {
    kind: GapKind;
    bordered?: boolean;
}) {
    return (
        <GridHead
            aria-hidden="true"
            className={cn('p-0', GAP_WIDTH[kind], bordered && 'border-b')}
        />
    );
}

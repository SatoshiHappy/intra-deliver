import { router } from '@inertiajs/react';
import { StatusBadge } from '@/components/StatusBadge';
import { TableCell } from '@/components/ui/table';
import { toastErrors } from '@/lib/flashToast';
import { nameOrCode } from '@/lib/nameOrCode';
import { noticeSubLine, noticeTemplate } from '@/lib/Notice/noticeText';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import { read } from '@/routes/notice';
import type { NoticeRow } from '@/types';

type Props = {
    row: NoticeRow;
    /** stacked = 鈴の中の 1 行(縦に積む)、cells = 通知の一覧の表の 1 行(4 つのセル) */
    layout: 'stacked' | 'cells';
    /** 押して送信する直前に呼ぶ(鈴を閉じる) */
    onOpen?: () => void;
};

/**
 * 未読の印(語を出し、色だけで伝えない)。
 */
function UnreadMark() {
    return (
        <span className="shrink-0 rounded-sm bg-primary/10 px-1.5 text-xs font-medium text-primary">
            未読
        </span>
    );
}

/**
 * 文。押すと既読にして移り先へ(移り先の判定はサーバー = docs の 設計/ワークフロー/案件の運用/通知.md の 5 節)。
 * stretch は押せる範囲を行の全体に広げる(鈴の中)。
 */
function SentenceButton({
    row,
    stretch,
    onOpen,
}: {
    row: NoticeRow;
    stretch: boolean;
    onOpen?: () => void;
}) {
    return (
        <button
            type="button"
            className={cn(
                'cursor-pointer text-left text-sm wrap-anywhere text-foreground hover:underline',
                row.readAt === null && 'font-semibold',
                stretch && 'after:absolute after:inset-0',
            )}
            onClick={() => {
                onOpen?.();
                router.visit(read({ notice: row.id }), {
                    onError: toastErrors,
                });
            }}
        >
            {noticeTemplate(row).sentence}
        </button>
    );
}

function SubLine({ row }: { row: NoticeRow }) {
    const text = noticeSubLine(row);

    return text === null ? null : (
        <p className="text-xs text-muted-foreground">{text}</p>
    );
}

function actorText(row: NoticeRow): string {
    return row.actor === null ? EMPTY_VALUE : nameOrCode(row.actor);
}

function KindBadge({ row }: { row: NoticeRow }) {
    const { label, tone } = noticeTemplate(row);

    return <StatusBadge label={label} tone={tone} />;
}

/**
 * 通知の 1 行(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.5)。鈴の中と通知の一覧の表が同じ部品を使い、
 * 並べ方だけ layout で変える: 1 行目に種類の札・起こした人・未読の印・日時、2 行目に文、その下に対象の 1 行。
 */
export function NoticeRowView({ row, layout, onOpen }: Props) {
    if (layout === 'cells') {
        return (
            <>
                <TableCell>
                    <KindBadge row={row} />
                </TableCell>
                <TableCell className="min-w-48 whitespace-normal">
                    <div className="flex items-start gap-2">
                        {row.readAt === null && <UnreadMark />}
                        <div className="flex flex-col gap-0.5">
                            <SentenceButton
                                row={row}
                                stretch={false}
                                onOpen={onOpen}
                            />
                            <SubLine row={row} />
                        </div>
                    </div>
                </TableCell>
                <TableCell>{actorText(row)}</TableCell>
                <TableCell className="font-num tabular-nums">
                    {formatDatetime(row.createdAt)}
                </TableCell>
            </>
        );
    }

    return (
        <div className="relative flex flex-col gap-1 px-4 py-3 hover:bg-accent">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                <KindBadge row={row} />
                <span>{actorText(row)}</span>
                {row.readAt === null && <UnreadMark />}
                <time className="ml-auto font-num tabular-nums">
                    {formatDatetime(row.createdAt)}
                </time>
            </div>
            <SentenceButton row={row} stretch onOpen={onOpen} />
            <SubLine row={row} />
        </div>
    );
}

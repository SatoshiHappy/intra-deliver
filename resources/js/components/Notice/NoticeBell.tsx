import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useState } from 'react';
import { NoticeRowView } from '@/components/Notice/NoticeRowView';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import type { NoticeLatestState } from '@/hooks/Notice/use-notice-latest';
import { useNoticeLatest } from '@/hooks/Notice/use-notice-latest';
import { bellLabel, unreadBadgeText } from '@/lib/Notice/noticeText';
import { cn } from '@/lib/utils';
import { index } from '@/routes/notice';

/**
 * 鈴の右上の件数の札(99 を超えたら「99+」)。読み上げは鈴の名前に件数があるので隠す。
 */
function UnreadCountMark({ unread }: { unread: number }) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'absolute top-1 right-0.5 min-w-4 rounded-full px-1 text-center',
                'bg-primary font-num text-[10px] leading-4 text-primary-foreground tabular-nums',
            )}
        >
            {unreadBadgeText(unread)}
        </span>
    );
}

/** 読み込み中に出す仮の行の数 */
const LOADING_ROWS = 3;

/**
 * 読み込み中の仮の行(開いた直後に中身が空に見えないように。前に開いたときの行は見せない)。
 */
function LoadingRows() {
    return (
        <ul aria-hidden="true" className="divide-y">
            {Array.from({ length: LOADING_ROWS }, (_, index) => (
                <li key={index} className="space-y-2 px-4 py-3">
                    <Skeleton className="h-4 w-2/3" />
                    <Skeleton className="h-4 w-full" />
                </li>
            ))}
        </ul>
    );
}

/**
 * 最新 10 件の行。取得している間は仮の行(前に開いたときの行は出さない)、失敗は 1 行だけ、行(0 件は「通知はありません」)の順に描き分ける。
 */
function LatestRows({
    state,
    onOpen,
}: {
    state: NoticeLatestState;
    onOpen: () => void;
}) {
    if (state.status === 'waiting') {
        return <LoadingRows />;
    }

    if (state.status === 'failed') {
        return (
            <p className="px-4 py-6 text-center text-sm">
                通知を取得できませんでした
            </p>
        );
    }

    if (state.rows.length === 0) {
        return (
            <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                通知はありません
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {state.rows.map((row) => (
                <li key={row.id}>
                    <NoticeRowView row={row} layout="stacked" onOpen={onOpen} />
                </li>
            ))}
        </ul>
    );
}

/**
 * 鈴の中身: 見出し「通知」・最新 10 件・「すべて見る」(通知の一覧へ)。
 */
function LatestPanel({
    state,
    onClose,
}: {
    state: NoticeLatestState;
    onClose: () => void;
}) {
    return (
        <>
            <h2 className="border-b px-4 py-3 text-sm font-semibold">通知</h2>
            <div className="max-h-[60vh] overflow-y-auto">
                <LatestRows state={state} onOpen={onClose} />
            </div>
            <div className="border-t px-4 py-2 text-right text-sm">
                <Link
                    href={index()}
                    className="text-primary underline-offset-4 hover:underline"
                    onClick={onClose}
                >
                    すべて見る
                </Link>
            </div>
        </>
    );
}

/**
 * ヘッダーの鈴(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.5)。ゲストには出さない(共有プロパティ noticeSummary が null)。
 * 開くたびに、最新 10 件を JSON の口から引く(最初の応答には入らない = 設計/ワークフロー/案件の運用/通知.md の 7 節)。鈴を開いても件数の札は取り直さない。
 */
export function NoticeBell() {
    const { noticeSummary } = usePage().props;
    const [open, setOpen] = useState(false);
    const { state, load } = useNoticeLatest();

    if (noticeSummary === null) {
        return null;
    }

    function onOpenChange(next: boolean) {
        setOpen(next);

        if (next) {
            load();
        }
    }

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-11 cursor-pointer text-muted-foreground"
                    aria-label={bellLabel(noticeSummary.unreadCount)}
                >
                    <Bell className="size-5" />
                    {noticeSummary.unreadCount > 0 && (
                        <UnreadCountMark unread={noticeSummary.unreadCount} />
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-96 p-0">
                <LatestPanel state={state} onClose={() => setOpen(false)} />
            </PopoverContent>
        </Popover>
    );
}

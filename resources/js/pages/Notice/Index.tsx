import { Head, router } from '@inertiajs/react';
import { NoticeFilterTabs } from '@/components/Notice/NoticeFilterTabs';
import { NoticeRowView } from '@/components/Notice/NoticeRowView';
import { Pagination } from '@/components/Pagination';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { toastErrors } from '@/lib/flashToast';
import { index, readAll } from '@/routes/notice';
import type { NoticeFilter, NoticeIndexProps, NoticeRow } from '@/types';

/** タブ・ページを変えたときに取り直す props */
const LIST_PROPS = ['query', 'counts', 'notices'];

/** 0 件のときの文(タブごと) */
const EMPTY_TEXTS: Record<NoticeFilter, string> = {
    all: '通知はありません',
    unread: '未読の通知はありません',
};

/**
 * 一覧の URL(既定の値は付けない。タブの既定 = すべて、ページの既定 = 1)。
 */
function listUrl(filter: NoticeFilter, page = 1) {
    return index.url({
        query: {
            filter: filter === 'all' ? undefined : filter,
            page: page === 1 ? undefined : page,
        },
    });
}

/**
 * 「すべて既読にする」(自分宛の未読をすべて既読にして元の画面へ戻る。未読が無くても押せる)。
 */
function ReadAllButton() {
    return (
        <Button
            variant="outline"
            onClick={() =>
                router.visit(readAll(), {
                    preserveScroll: true,
                    onError: toastErrors,
                })
            }
        >
            すべて既読にする
        </Button>
    );
}

/**
 * 通知の表(種類の札・内容・だれが・日時)。行の中身は鈴と同じ部品。
 */
function NoticeTable({
    rows,
    emptyText,
}: {
    rows: NoticeRow[];
    emptyText: string;
}) {
    return (
        <Table aria-label="通知">
            <TableHeader>
                <TableRow>
                    <TableHead>種類</TableHead>
                    <TableHead>内容</TableHead>
                    <TableHead>だれが</TableHead>
                    <TableHead>日時</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.length === 0 && (
                    <TableRow>
                        <TableCell
                            colSpan={4}
                            className="text-center text-muted-foreground"
                        >
                            {emptyText}
                        </TableCell>
                    </TableRow>
                )}
                {rows.map((row) => (
                    <TableRow key={row.id}>
                        <NoticeRowView row={row} layout="cells" />
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * 通知の一覧(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.5): 見出しと「すべて既読にする」、タブ「すべて」「未読 {n}」、
 * 表(種類の札・内容・だれが・日時)とページ送り。タブ・ページは部分再読み込み。行の部品は鈴と同じ。
 */
export default function Index({ query, counts, notices }: NoticeIndexProps) {
    return (
        <>
            <Head title="通知" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold">通知</h1>
                        <p className="text-sm text-muted-foreground">
                            あなた宛の通知を、新しい順に表示します。
                        </p>
                    </div>
                    <ReadAllButton />
                </div>
                <NoticeFilterTabs
                    current={query.filter}
                    unreadCount={counts.unread}
                    urlOf={(filter) => listUrl(filter)}
                    only={LIST_PROPS}
                />
                <section
                    aria-label="通知の一覧"
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <NoticeTable
                        rows={notices.data}
                        emptyText={EMPTY_TEXTS[query.filter]}
                    />
                    <Pagination
                        list={notices}
                        onPage={(page) =>
                            router.get(
                                listUrl(query.filter, page),
                                {},
                                { only: LIST_PROPS, preserveState: true },
                            )
                        }
                    />
                </section>
            </div>
        </>
    );
}

Index.layout = {
    breadcrumbs: [{ title: '通知', href: index() }],
};

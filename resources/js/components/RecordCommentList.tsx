import type { ReactNode } from 'react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordPanelMore } from '@/components/RecordPanelMore';
import { RichText } from '@/components/RichText';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { canDeleteComment } from '@/lib/recordComments';
import { personText } from '@/lib/recordShow';
import { formatDatetime } from '@/lib/recordValues';
import type { RecordCommentRow } from '@/types';
import type { RouteDefinition } from '@/wayfinder';

type Props = {
    rows: RecordCommentRow[];
    hasMore: boolean;
    /** ほかの人の分も消せる(コメントの管理の権限) */
    canDeleteOthers: boolean;
    /** 行の削除の送り先(自分の分と管理で URL が違うので呼び手が決める。部品はルートの名前を知らない) */
    deleteActionOf: (row: RecordCommentRow) => RouteDefinition<'delete'>;
    loading: boolean;
    onMore: (beforeId: number) => void;
};

const AT = 'font-num text-xs text-muted-foreground tabular-nums';

/** 頭の丸(頭文字。削除の印の行は「×」) */
function Mark({ text }: { text: string }) {
    return (
        <Avatar className="mt-0.5 size-[26px]" aria-hidden="true">
            <AvatarFallback className="bg-primary text-xs font-bold text-primary-foreground">
                {text}
            </AvatarFallback>
        </Avatar>
    );
}

/** 削除の印の行(本文の代わりの 1 行と、削除した人・日時。書いた人・宛先は出さない) */
function DeletedComment({ row }: { row: RecordCommentRow }) {
    const { deleted } = row;

    return (
        <li className="flex gap-2.5 px-3 py-2.5 text-muted-foreground">
            <Avatar className="mt-0.5 size-[26px]" aria-hidden="true">
                <AvatarFallback>×</AvatarFallback>
            </Avatar>
            <div className="grid min-w-0 flex-1 gap-1">
                <p>このコメントは削除されました。</p>
                <p className="flex flex-wrap items-center gap-x-2 text-xs">
                    <span className="min-w-0 truncate whitespace-nowrap">
                        {`削除した人　${personText(deleted?.by ?? null)}`}
                    </span>
                    <span className={`${AT} whitespace-nowrap`}>
                        {deleted?.at ? formatDatetime(deleted.at) : ''}
                    </span>
                </p>
            </div>
        </li>
    );
}

/** 1 行の見出し(名前と日時は折り返さず、幅が足りなければ日時が次の行へ。「削除」は 1 行目の右端に固定) */
function CommentHead({
    row,
    author,
    deleteButton,
}: {
    row: RecordCommentRow;
    author: string;
    deleteButton: ReactNode;
}) {
    return (
        <div className="flex items-start gap-2">
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2">
                <b className="min-w-0 truncate whitespace-nowrap">{author}</b>
                <span className={`${AT} whitespace-nowrap`}>
                    {row.createdAt === null
                        ? ''
                        : formatDatetime(row.createdAt)}
                </span>
            </div>
            {deleteButton !== null && (
                <span className="shrink-0">{deleteButton}</span>
            )}
        </div>
    );
}

/** 1 行(書いた人・日時・削除、本文、宛先) */
function CommentItem({
    row,
    canDelete,
    deleteAction,
}: {
    row: RecordCommentRow;
    canDelete: boolean;
    deleteAction: RouteDefinition<'delete'>;
}) {
    const author = personText(row.author);
    const deleteButton = canDelete ? (
        <ConfirmDeleteDialog
            triggerLabel={`${author}のコメントを削除`}
            title="コメントの削除"
            description="このコメントを削除しますか。削除したことは一覧に表示されます。"
            action={deleteAction}
            preserveScroll
        />
    ) : null;

    return (
        <li className="flex gap-2.5 px-3 py-2.5">
            <Mark text={Array.from(author)[0]} />
            <div className="grid min-w-0 flex-1 gap-1">
                <CommentHead
                    row={row}
                    author={author}
                    deleteButton={deleteButton}
                />
                <RichText source={row.body ?? ''} />
                {row.mentions.length > 0 && (
                    <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                        宛先
                        {row.mentions.map((person) => (
                            <Badge key={person.id} variant="secondary">
                                {personText(person)}
                            </Badge>
                        ))}
                    </div>
                )}
            </div>
        </li>
    );
}

/**
 * コメントの一覧(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節): 新しい順の 1 件 = 書いた人・日時・本文(複数行の欄と
 * 同じ RichText。画面/README.md 1.4)・宛先。「削除」は自分の分と、管理の権限がある人に出す(押すと確かめの Dialog)。
 * 削除した行は印だけ。5 件ずつ「さらに表示」。
 */
export function RecordCommentList(props: Props) {
    const { rows, canDeleteOthers } = props;
    const last = rows.at(-1);

    return (
        <div className="grid gap-2.5">
            {rows.length === 0 ? (
                <p className="text-muted-foreground">コメントはありません</p>
            ) : (
                <ul
                    aria-label="コメントの一覧"
                    className="divide-y rounded-md border bg-background"
                >
                    {rows.map((row) =>
                        row.deleted === null ? (
                            <CommentItem
                                key={row.id}
                                row={row}
                                canDelete={canDeleteComment(
                                    row,
                                    canDeleteOthers,
                                )}
                                deleteAction={props.deleteActionOf(row)}
                            />
                        ) : (
                            <DeletedComment key={row.id} row={row} />
                        ),
                    )}
                </ul>
            )}
            {props.hasMore && last !== undefined && (
                <RecordPanelMore
                    loading={props.loading}
                    onMore={() => props.onMore(last.id)}
                />
            )}
        </div>
    );
}

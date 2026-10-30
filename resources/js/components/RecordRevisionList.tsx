import { FileText } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { RecordPanelMore } from '@/components/RecordPanelMore';
import { RecordRevisionValueChange } from '@/components/RecordRevisionValueChange';
import { RecordValue } from '@/components/RecordValue';
import { StatusBadge } from '@/components/StatusBadge';
import {
    changeBadge,
    revisionActorText,
    revisionOperation,
} from '@/lib/recordShow';
import type { DetailRowCell } from '@/lib/recordRevisions';
import { detailRowCells } from '@/lib/recordRevisions';
import { formatDate, formatDatetime } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import type {
    RecordRevisionAttachmentChange,
    RecordRevisionChanges,
    RecordRevisionRow,
} from '@/types';

function Change({
    change,
    label,
    children,
}: {
    change: 'added' | 'removed' | 'changed';
    label: ReactNode;
    children: ReactNode;
}) {
    const badge = changeBadge(change);

    return (
        <li className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <StatusBadge label={badge.label} tone={badge.tone} />
            <span className="text-muted-foreground">{label}</span>
            <span className="min-w-0 basis-full wrap-anywhere">{children}</span>
        </li>
    );
}

function AttachmentChange({
    change,
}: {
    change: RecordRevisionAttachmentChange;
}) {
    const url = change.previewUrl ?? change.downloadUrl;
    const purged = change.purgeDueOn !== null;

    return (
        <Change change={change.change} label="添付ファイル">
            <span className="inline-flex items-center gap-2 font-medium">
                <FileText
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                {change.change === 'removed' && url !== null ? (
                    <a
                        href={url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-primary hover:underline"
                    >
                        {change.name}
                    </a>
                ) : (
                    change.name
                )}
            </span>
            {change.change === 'removed' && (
                <span className="block text-xs text-muted-foreground">
                    {purged
                        ? `削除予定日(${formatDate(change.purgeDueOn ?? '')})を過ぎたため削除しました。`
                        : '外した時点のファイルを表示できます。'}
                </span>
            )}
        </Change>
    );
}

/** 明細の行の表示(明細の名前と、先頭の項目の値) */
function DetailRowName({
    detailName,
    cells,
}: {
    detailName: string;
    cells: DetailRowCell[];
}) {
    const first = cells.at(0);

    return (
        <>
            {`${detailName}　`}
            {first !== undefined && (
                <RecordValue
                    field={first.definition}
                    value={first.value}
                    place="diff"
                />
            )}
        </>
    );
}

/** 明細の行の値の要約(先頭の項目より後の項目の「<名前> <値>」を全角の空白でつなぐ) */
function DetailRowSummary({ cells }: { cells: DetailRowCell[] }) {
    return cells.slice(1).map((cell, index) => (
        <Fragment key={cell.definition.code}>
            {index > 0 && '　'}
            {cell.name}{' '}
            <RecordValue
                field={cell.definition}
                value={cell.value}
                place="diff"
            />
        </Fragment>
    ));
}

function DetailChanges({
    detail,
    row,
}: {
    detail: RecordRevisionChanges['details'][number];
    row: RecordRevisionRow;
}) {
    return detail.rows.flatMap((rowChange) => {
        const cells = detailRowCells(
            detail.fields,
            rowChange.after ?? rowChange.before,
        );
        const name = <DetailRowName detailName={detail.name} cells={cells} />;

        if (rowChange.change !== 'changed') {
            return [
                <Change
                    key={`${rowChange.rowId}`}
                    change={rowChange.change}
                    label={name}
                >
                    <DetailRowSummary cells={cells} />
                </Change>,
            ];
        }

        return detail.fields
            .filter((field) => rowChange.changedCodes.includes(field.code))
            .map((field) => (
                <Change
                    key={`${rowChange.rowId}-${field.code}`}
                    change="changed"
                    label={
                        <>
                            {name}　{field.name}
                        </>
                    }
                >
                    <RecordRevisionValueChange
                        field={field}
                        before={rowChange.before?.[field.code] ?? null}
                        after={rowChange.after?.[field.code] ?? null}
                        row={row}
                    />
                </Change>
            ));
    });
}

function Changes({ row }: { row: RecordRevisionRow }) {
    if (row.noPreviousRecord) {
        return (
            <p className="text-muted-foreground">
                この版より前の記録はありません。
            </p>
        );
    }

    if (row.changes === null) {
        return null;
    }

    return (
        <ul className="mt-1.5 grid gap-2">
            {row.changes.fields.map((change) => (
                <Change key={change.code} change="changed" label={change.name}>
                    <RecordRevisionValueChange
                        field={change.field}
                        before={change.before}
                        after={change.after}
                        row={row}
                    />
                </Change>
            ))}
            {row.changes.details.map((detail) => (
                <DetailChanges key={detail.code} detail={detail} row={row} />
            ))}
            {row.changes.attachments.map((change) => (
                <AttachmentChange
                    key={`${change.change}-${change.id}`}
                    change={change}
                />
            ))}
        </ul>
    );
}

/** 時系列の 1 行(左の線と丸 = モック 2) */
const TIMELINE_ITEM = cn(
    'relative pb-5 pl-7 last:pb-0',
    'before:absolute before:top-5 before:-bottom-0.5 before:left-[7px] before:w-0.5',
    'before:bg-border last:before:hidden',
    'after:absolute after:top-1 after:left-0 after:size-4 after:rounded-full after:bg-primary',
);

/** 頭の 1 行(日時・操作した人・操作 = 更新履歴.md の 5.1 節) */
function RevisionHead({ row }: { row: RecordRevisionRow }) {
    const operation = revisionOperation(row);

    return (
        <div className="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
            <span className="font-num text-muted-foreground tabular-nums">
                {row.at === null ? '' : formatDatetime(row.at)}
            </span>
            <span>{revisionActorText(row)}</span>
            <span className="font-bold">{operation.label}</span>
            {operation.nodeName !== null && (
                <span className="text-muted-foreground">
                    {operation.nodeName}
                </span>
            )}
        </div>
    );
}

/**
 * サイドパネルの「更新履歴」の欄(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5 節): 新しい版から、頭と変化
 * (項目 → 明細 → 添付)。登録・差分の無い版は頭だけ、前の版の無い更新の版は 1 行。外した添付の名前は外した時点のファイルを開く。
 */
export function RecordRevisionList({
    rows,
    hasMore,
    loading,
    onMore,
}: {
    rows: RecordRevisionRow[];
    hasMore: boolean;
    loading: boolean;
    onMore: (beforeId: number) => void;
}) {
    const last = rows.at(-1);

    return (
        <div className="grid gap-2">
            <ol className="grid">
                {rows.map((row) => (
                    <li key={row.id} className={TIMELINE_ITEM}>
                        <RevisionHead row={row} />
                        <Changes row={row} />
                    </li>
                ))}
            </ol>
            {hasMore && last !== undefined && (
                <RecordPanelMore
                    loading={loading}
                    onMore={() => onMore(last.id)}
                />
            )}
        </div>
    );
}

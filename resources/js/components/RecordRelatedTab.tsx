import { Link } from '@inertiajs/react';
import { Check, Plus } from 'lucide-react';
import { Pagination } from '@/components/Pagination';
import { RecordValue } from '@/components/RecordValue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { hasLinkValue, updatedDate } from '@/lib/recordRelated';
import { EMPTY_VALUE } from '@/lib/recordValues';
import type { RecordRelatedEntry, RecordRelatedRows } from '@/types';

const LINK_CLASS = 'text-primary underline-offset-4 hover:underline';

/** 行の詳細の URL(呼び手のページがルートから作る = 詳細の画面/README.md の 7 節) */
type RowHref = (moduleCode: string, recordId: number) => string;

/**
 * 左のタブ「関連レコード」のメニュー(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 3 節): 見える入口ごとに名前と件数
 * (選んでいる入口に印)、出せる入口だけ右に「新規」(4.1 節)。
 */
export function RecordRelatedMenuItems({
    entries,
    selectedKey,
    onSelect,
    onNew,
}: {
    entries: RecordRelatedEntry[];
    selectedKey: string | null;
    onSelect: (key: string) => void;
    onNew: (key: string) => void;
}) {
    return entries.map((entry) => (
        <div key={entry.key} className="flex items-center gap-1">
            <DropdownMenuItem
                className="flex-1 justify-between gap-3"
                onSelect={() => onSelect(entry.key)}
            >
                <span>{entry.name}</span>
                <span className="flex items-center gap-1.5">
                    <span className="font-num text-muted-foreground tabular-nums">
                        {entry.count}
                    </span>
                    <Check
                        aria-hidden="true"
                        className={
                            entry.key === selectedKey
                                ? 'size-4'
                                : 'invisible size-4'
                        }
                    />
                </span>
            </DropdownMenuItem>
            {entry.canCreate && (
                <DropdownMenuItem
                    aria-label={`${entry.name}の新規`}
                    className="text-primary"
                    onSelect={() => onNew(entry.key)}
                >
                    新規
                </DropdownMenuItem>
            )}
        </div>
    ));
}

/**
 * 行の先頭の列のセル(値を参照元の詳細へのリンクにする。値が空なら #<id> をリンクの文字に = 2 節)。
 */
function FirstCell({
    rows,
    row,
    rowHref,
}: {
    rows: RecordRelatedRows;
    row: RecordRelatedRows['rows'][number];
    rowHref: RowHref;
}) {
    const column = rows.columns[0];
    const value =
        column === undefined ? null : (row.values[column.code] ?? null);

    return (
        <TableCell className="wrap-anywhere whitespace-normal">
            <Link
                href={rowHref(rows.module.code, row.id)}
                className={LINK_CLASS}
            >
                {column !== undefined && hasLinkValue(value) ? (
                    <RecordValue field={column} value={value} place="cell" />
                ) : (
                    <span className="font-num tabular-nums">#{row.id}</span>
                )}
            </Link>
        </TableCell>
    );
}

/**
 * 入口の表(列 = 選んだ列か未選択の列の後に固定の「更新日」。列が無ければ #<id> の列だけ = 2 節)。
 */
function RelatedTable({
    rows,
    rowHref,
}: {
    rows: RecordRelatedRows;
    rowHref: RowHref;
}) {
    const rest = rows.columns.slice(1);

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{rows.columns[0]?.name ?? 'ID'}</TableHead>
                    {rest.map((column) => (
                        <TableHead key={column.code}>{column.name}</TableHead>
                    ))}
                    <TableHead>更新日</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.rows.map((row) => (
                    <TableRow key={row.id}>
                        <FirstCell rows={rows} row={row} rowHref={rowHref} />
                        {rest.map((column) => (
                            <TableCell
                                key={column.code}
                                className="wrap-anywhere whitespace-normal"
                            >
                                <RecordValue
                                    field={column}
                                    value={row.values[column.code] ?? null}
                                    place="cell"
                                />
                            </TableCell>
                        ))}
                        <TableCell className="font-num tabular-nums">
                            {row.updatedAt === null
                                ? EMPTY_VALUE
                                : updatedDate(row.updatedAt)}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/** 表の上の行(入口の名前・件数・「新規」= 4.1 節の条件のとき) */
function RelatedHead({
    entry,
    total,
    onNew,
}: {
    entry: RecordRelatedEntry;
    total: number;
    onNew: (key: string) => void;
}) {
    return (
        <div className="flex items-center gap-2">
            <Badge variant="secondary">{entry.name}</Badge>
            <span className="font-num text-sm text-muted-foreground tabular-nums">
                {total} 件
            </span>
            {entry.canCreate && (
                <Button
                    size="sm"
                    variant="outline"
                    className="ml-auto"
                    onClick={() => onNew(entry.key)}
                >
                    <Plus aria-hidden="true" />
                    新規
                </Button>
            )}
        </div>
    );
}

type TabProps = {
    entries: RecordRelatedEntry[];
    selectedKey: string | null;
    rows: RecordRelatedRows | null;
    loading: boolean;
    onPage: (page: number) => void;
    onNew: (key: string) => void;
    rowHref: RowHref;
};

/**
 * 左のタブ「関連レコード」の中身(1 節・3 節): 選んだ入口の名前・件数・「新規」(4.1 節の条件のとき)と、その入口の表(更新日時の降順、
 * 1 ページ 20 件)とページ送り。表がまだ届いていなければ「読み込んでいます」。
 */
export function RecordRelatedTab({
    entries,
    selectedKey,
    rows,
    loading,
    onPage,
    onNew,
    rowHref,
}: TabProps) {
    const entry = entries.find((item) => item.key === selectedKey);

    if (entry === undefined) {
        return null;
    }

    return (
        <div className="flex flex-col gap-3">
            <RelatedHead
                entry={entry}
                total={rows === null ? entry.count : rows.total}
                onNew={onNew}
            />
            {rows === null ? (
                <p className="text-sm text-muted-foreground">
                    読み込んでいます
                </p>
            ) : rows.rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    関連レコードはありません
                </p>
            ) : (
                <RelatedTable rows={rows} rowHref={rowHref} />
            )}
            {rows !== null && (
                <Pagination list={rows} onPage={onPage} disabled={loading} />
            )}
        </div>
    );
}

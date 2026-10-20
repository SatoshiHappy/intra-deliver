import { Link } from '@inertiajs/react';
import { HomePanel } from '@/components/Menu/HomePanel';
import { StatusBadge } from '@/components/StatusBadge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDatetime } from '@/lib/recordValues';
import type { HomeTableSection } from '@/types';

type Column = HomeTableSection['columns'][number];
type Cell = HomeTableSection['rows'][number]['cells'][number];

/**
 * 1 つのセル(列の型で描き分ける。docs の 設計/メニュー/ホーム.md 6.2)。title は行の url へのリンクで、リンクを行全体に広げる
 * (行は relative と transform で包含ブロックにする = WebKit は tr の relative だけでは包含ブロックにしない(WebKit Bugzilla 240961)。行全体を押せる)。date・datetime は font-num tabular-nums、status は状態の札(tone が無ければ素の文字)。
 */
function HomeTableCell({
    column,
    cell,
    url,
}: {
    column: Column;
    cell: Cell;
    url: string;
}) {
    switch (column.type) {
        case 'title':
            return (
                <TableCell className="min-w-40 font-medium wrap-anywhere whitespace-normal">
                    <Link
                        href={url}
                        className="after:absolute after:inset-0 focus-visible:outline-offset-[-2px]"
                    >
                        {cell.text}
                    </Link>
                </TableCell>
            );
        case 'date':
            return (
                <TableCell className="font-num tabular-nums">
                    {formatDate(cell.text)}
                </TableCell>
            );
        case 'datetime':
            return (
                <TableCell className="font-num tabular-nums">
                    {formatDatetime(cell.text)}
                </TableCell>
            );
        case 'status':
            return (
                <TableCell>
                    {cell.tone === null ? (
                        cell.text
                    ) : (
                        <StatusBadge
                            label={cell.text}
                            tone={cell.tone}
                            mark={cell.mark}
                        />
                    )}
                </TableCell>
            );
        default:
            return <TableCell>{cell.text}</TableCell>;
    }
}

/**
 * 表の節(docs の 設計/メニュー/ホーム.md 6.3)。面の中に段 1 の表の作法(見出し行・横線だけ)で描き、行に乗ったら --accent。
 * 0 行なら emptyText を面の中央に出す。
 */
export function HomeTable({ section }: { section: HomeTableSection }) {
    return (
        <HomePanel
            code={section.code}
            heading={section.heading}
            more={section.more}
        >
            {section.rows.length === 0 ? (
                <p className="py-7 text-center text-sm text-muted-foreground">
                    {section.emptyText}
                </p>
            ) : (
                <Table aria-label={section.heading}>
                    <TableHeader>
                        <TableRow>
                            {section.columns.map((column) => (
                                <TableHead key={column.code}>
                                    {column.label}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {section.rows.map((row) => (
                            <TableRow
                                key={row.code}
                                className="relative [transform:translateZ(0)] hover:bg-accent"
                            >
                                {section.columns.map((column, index) => (
                                    <HomeTableCell
                                        key={column.code}
                                        column={column}
                                        cell={row.cells[index]}
                                        url={row.url}
                                    />
                                ))}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </HomePanel>
    );
}

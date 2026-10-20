import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/StatusBadge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { isLinkableUrl } from '@/lib/recordShow';
import type {
    RecordShowFailedSection,
    RecordShowLinesSection,
    RecordShowListSection,
    RecordShowSectionLink,
    RecordShowTableSection,
} from '@/types';

/** リンクにする URL(外れた URL は null = 文字だけで出す) */
function linkOf(url: string | null): string | null {
    return url !== null && isLinkableUrl(url) ? url : null;
}

/** URL がリンクにできればリンク、外れた URL と null は文字だけ */
function MaybeLink({
    url,
    children,
}: {
    url: string | null;
    children: ReactNode;
}) {
    const link = linkOf(url);

    return link === null ? (
        children
    ) : (
        <a href={link} className="text-primary hover:underline">
            {children}
        </a>
    );
}

/** 「すべて表示」(外れた URL なら出さない) */
function MoreLink({ more }: { more: RecordShowSectionLink | null }) {
    const link = more === null ? null : linkOf(more.url);

    return more === null || link === null ? null : (
        <a
            href={link}
            className="text-sm font-medium text-primary underline-offset-4 hover:underline"
        >
            {more.label}
        </a>
    );
}

function Empty({ text }: { text: string }) {
    return <p className="text-muted-foreground">{text}</p>;
}

function TableSection({ section }: { section: RecordShowTableSection }) {
    if (section.rows.length === 0) {
        return <Empty text={section.emptyText} />;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {section.columns.map((column) => (
                        <TableHead key={column.code}>{column.label}</TableHead>
                    ))}
                </TableRow>
            </TableHeader>
            <TableBody>
                {section.rows.map((row) => (
                    <TableRow key={row.code}>
                        {row.cells.map((cell, index) => (
                            <TableCell
                                key={section.columns[index]?.code ?? index}
                                className="wrap-anywhere whitespace-normal"
                            >
                                {cell.tone !== null ? (
                                    <StatusBadge
                                        label={cell.text}
                                        tone={cell.tone}
                                    />
                                ) : (
                                    <MaybeLink
                                        url={index === 0 ? row.url : null}
                                    >
                                        {cell.text}
                                    </MaybeLink>
                                )}
                            </TableCell>
                        ))}
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

function ListSection({ section }: { section: RecordShowListSection }) {
    if (section.items.length === 0) {
        return <Empty text={section.emptyText} />;
    }

    return (
        <ul className="divide-y rounded-md border">
            {section.items.map((item) => (
                <li key={item.code} className="grid gap-0.5 px-3 py-2">
                    <span className="font-medium">
                        <MaybeLink url={item.url}>{item.title}</MaybeLink>
                    </span>
                    {(item.note !== null || item.at !== null) && (
                        <span className="text-xs text-muted-foreground">
                            {[item.note, item.at]
                                .filter((part) => part !== null)
                                .join('　')}
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

function LinesSection({ section }: { section: RecordShowLinesSection }) {
    return (
        <div className="grid gap-2">
            {section.note !== null && (
                <p className="text-muted-foreground">{section.note}</p>
            )}
            {section.items.length === 0 ? (
                <Empty text={section.emptyText} />
            ) : (
                <ol className="grid gap-1.5">
                    {section.items.map((item, index) => (
                        <li
                            key={index}
                            className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2"
                        >
                            <span className="font-num text-muted-foreground tabular-nums">
                                {item.time ?? ''}
                            </span>
                            <span className="wrap-anywhere">
                                {item.label !== null && (
                                    <b className="mr-2 font-medium">
                                        {item.label}
                                    </b>
                                )}
                                {item.text}
                            </span>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

/**
 * 業務ツールの節の中身(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 1 節・2 節): kind の 3 つを描き、
 * 失敗は「表示できませんでした」、節が中身を返さなかったときは「表示する内容がありません」。操作はリンクだけ。
 */
export function RecordShowSection({
    content,
}: {
    content:
        | RecordShowTableSection
        | RecordShowListSection
        | RecordShowLinesSection
        | RecordShowFailedSection
        | null;
}) {
    if (content === null) {
        return <Empty text="表示する内容がありません" />;
    }

    switch (content.kind) {
        case 'failed':
            return <Empty text="表示できませんでした" />;
        case 'table':
            return (
                <div className="grid gap-3">
                    <TableSection section={content} />
                    <MoreLink more={content.more} />
                </div>
            );
        case 'list':
            return (
                <div className="grid gap-3">
                    <ListSection section={content} />
                    <MoreLink more={content.more} />
                </div>
            );
        case 'lines':
            return <LinesSection section={content} />;
    }
}

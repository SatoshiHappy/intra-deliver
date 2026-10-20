import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { RecordSearchForm } from '@/components/Records/RecordSearchForm';
import { RecordValue } from '@/components/RecordValue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    EMPTY_VALUE,
    formatDatetime,
    formatRecordValue,
} from '@/lib/recordValues';
import { create, index, show } from '@/routes/records/data';
import type { QueryParams } from '@/wayfinder';
import type {
    RecordFieldDefinition,
    RecordList,
    RecordListQuery,
    RecordModuleDefinition,
} from '@/types';

type Props = {
    module: RecordModuleDefinition;
    records: RecordList;
    query: RecordListQuery;
    can: { create: boolean };
};

/** 検索・並び替え・ページを変えたときに取り直す props(定義 module は取り直さない = 画面/業務の画面.md 2.1) */
const LIST_PROPS = ['records', 'query'];

/** 既定の並び(id の降順) */
const DEFAULT_SORT = '-id';

/** 並べられる型(項目と規則/README.md 7 節。参照と明細は並べない) */
const SORTABLE_TYPES = [
    'string',
    'integer',
    'decimal',
    'date',
    'datetime',
    'boolean',
    'choice',
];

/** ページの番号を出す範囲(今のページの前後。最初と最後はいつも出し、間は「…」) */
const PAGE_WINDOW = 2;

/**
 * 一覧を、クエリ(検索条件・並び・ページ)を変えた URL へ部分再読み込みする。成功したら onSuccess(あれば)を呼ぶ。
 */
function visit(
    moduleCode: string,
    filter: QueryParams | null,
    sort: string,
    page?: number,
    onSuccess?: () => void,
) {
    router.get(
        index.url(moduleCode, {
            query: {
                filter: filter ?? undefined,
                sort: sort === DEFAULT_SORT ? undefined : sort,
                page: page === undefined || page === 1 ? undefined : page,
            },
        }),
        {},
        {
            only: LIST_PROPS,
            preserveState: true,
            preserveScroll: true,
            onSuccess,
        },
    );
}

/**
 * 出すページの番号(最初・最後・今の前後 PAGE_WINDOW。飛んだところは null =「…」)。
 */
function pageItems(currentPage: number, lastPage: number): (number | null)[] {
    const items: (number | null)[] = [];

    for (let page = 1; page <= lastPage; page += 1) {
        const isShown =
            page === 1 ||
            page === lastPage ||
            Math.abs(page - currentPage) <= PAGE_WINDOW;

        if (isShown) {
            items.push(page);
        } else if (items[items.length - 1] !== null) {
            items.push(null);
        }
    }

    return items;
}

/**
 * 並べられる列の見出し(押すと昇順 → 降順 → 既定。aria-sort を付ける)。
 */
function SortableHead({
    label,
    sortKey,
    current,
    onSort,
}: {
    label: string;
    sortKey: string;
    current: string;
    onSort: (sort: string) => void;
}) {
    const direction =
        current === sortKey
            ? 'ascending'
            : current === `-${sortKey}`
              ? 'descending'
              : 'none';
    const next =
        direction === 'none'
            ? sortKey
            : direction === 'ascending'
              ? `-${sortKey}`
              : DEFAULT_SORT;

    return (
        <TableHead aria-sort={direction}>
            <button
                type="button"
                onClick={() => onSort(next)}
                className="inline-flex items-center gap-1 font-medium hover:underline"
            >
                {label}
                {direction !== 'none' && (
                    <span aria-hidden="true">
                        {direction === 'ascending' ? '↑' : '↓'}
                    </span>
                )}
            </button>
        </TableHead>
    );
}

/**
 * ページング(前へ・次へ・ページの番号(最初・最後・今の前後 2。間は「…」)・件数)。
 */
function Pagination({
    records,
    onPage,
}: {
    records: RecordList;
    onPage: (page: number) => void;
}) {
    const pages = pageItems(records.currentPage, records.lastPage);

    return (
        <nav aria-label="ページ" className="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                disabled={records.currentPage <= 1}
                onClick={() => onPage(records.currentPage - 1)}
            >
                前へ
            </Button>
            {pages.map((page, position) =>
                page === null ? (
                    <span
                        key={`gap-${position}`}
                        aria-hidden="true"
                        className="px-1 text-muted-foreground"
                    >
                        …
                    </span>
                ) : (
                    <Button
                        key={page}
                        variant={
                            page === records.currentPage ? 'secondary' : 'ghost'
                        }
                        size="sm"
                        aria-current={
                            page === records.currentPage ? 'page' : undefined
                        }
                        onClick={() => onPage(page)}
                        className="font-num tabular-nums"
                    >
                        {page}
                    </Button>
                ),
            )}
            <Button
                variant="outline"
                size="sm"
                disabled={records.currentPage >= records.lastPage}
                onClick={() => onPage(records.currentPage + 1)}
            >
                次へ
            </Button>
            <span className="ml-2 text-sm text-muted-foreground">
                全 {records.total} 件
            </span>
        </nav>
    );
}

export default function Index({ module, records, query, can }: Props) {
    const listed = module.fields.filter((field) => field.isListed);
    const searchable = module.fields.filter((field) => field.isSearchable);
    const currentFilter = query.filters as QueryParams;
    // 検索(「検索」「条件を解除」)の訪問が成功するたびに、検索欄をサーバーが解釈した条件で作り直す(捨てられた条件を欄に残さない = 修正方針 B3)
    const [searchVersion, setSearchVersion] = useState(0);
    const refresh = () => setSearchVersion((version) => version + 1);
    // 並び替え・ページの訪問は今の条件をそのまま送るので、欄を作り直さない(未送信の入力を残す = 修正方針 D1)
    const sortBy = (sort: string) => visit(module.code, currentFilter, sort);
    const cell = (
        field: RecordFieldDefinition,
        record: RecordList['data'][number],
    ) => {
        const value = record.values[field.code] ?? null;

        return field.isTitle ? (
            <Link
                href={show({ module: module.code, record: record.id })}
                className="text-primary underline-offset-4 hover:underline"
            >
                {formatRecordValue(field, value, 'cell')}
            </Link>
        ) : (
            <RecordValue field={field} value={value} place="cell" />
        );
    };

    return (
        <>
            <Head title={module.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <h1 className="text-2xl font-semibold">{module.name}</h1>
                    {can.create && (
                        <Button asChild>
                            <Link href={create(module.code)}>登録</Link>
                        </Button>
                    )}
                </div>
                {searchable.length > 0 && (
                    <RecordSearchForm
                        version={searchVersion}
                        fields={searchable}
                        query={query}
                        onSearch={(filter) =>
                            visit(
                                module.code,
                                filter,
                                query.sort,
                                undefined,
                                refresh,
                            )
                        }
                    />
                )}
                <section
                    aria-label={`${module.name}の一覧`}
                    className="flex flex-col gap-4 rounded-xl border bg-card p-5 text-card-foreground"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <SortableHead
                                    label="#"
                                    sortKey="id"
                                    current={query.sort}
                                    onSort={sortBy}
                                />
                                {listed.map((field) =>
                                    SORTABLE_TYPES.includes(field.type) ? (
                                        <SortableHead
                                            key={field.code}
                                            label={field.name}
                                            sortKey={field.code}
                                            current={query.sort}
                                            onSort={sortBy}
                                        />
                                    ) : (
                                        <TableHead key={field.code}>
                                            {field.name}
                                        </TableHead>
                                    ),
                                )}
                                <SortableHead
                                    label="更新日時"
                                    sortKey="updated_at"
                                    current={query.sort}
                                    onSort={sortBy}
                                />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {records.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={listed.length + 2}
                                        className="text-center text-muted-foreground"
                                    >
                                        レコードはありません
                                    </TableCell>
                                </TableRow>
                            )}
                            {records.data.map((record) => (
                                <TableRow key={record.id}>
                                    <TableCell>
                                        <Link
                                            href={show({
                                                module: module.code,
                                                record: record.id,
                                            })}
                                            className="font-num text-primary tabular-nums underline-offset-4 hover:underline"
                                        >
                                            {record.id}
                                        </Link>
                                    </TableCell>
                                    {listed.map((field) => (
                                        <TableCell key={field.code}>
                                            {cell(field, record)}
                                        </TableCell>
                                    ))}
                                    <TableCell className="font-num tabular-nums">
                                        {record.updatedAt === null
                                            ? EMPTY_VALUE
                                            : formatDatetime(record.updatedAt)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Pagination
                        records={records}
                        onPage={(page) =>
                            visit(module.code, currentFilter, query.sort, page)
                        }
                    />
                </section>
            </div>
        </>
    );
}

Index.layout = (props: Props) => ({
    breadcrumbs: [{ title: props.module.name, href: index(props.module.code) }],
});

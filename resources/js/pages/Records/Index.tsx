import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { HeadingHelp } from '@/components/HeadingHelp';
import { Pagination } from '@/components/Pagination';
import { RecordAddonActions } from '@/components/Records/RecordAddonActions';
import { RecordListRows } from '@/components/Records/RecordListRows';
import { RecordSearchForm } from '@/components/Records/RecordSearchForm';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { moduleDescriptionHelp } from '@/lib/Records/moduleDescription';
import { create, index } from '@/routes/records/data';
import type { QueryParams } from '@/wayfinder';
import type {
    RecordAddonBadge,
    RecordIndexAddons,
    RecordList,
    RecordListQuery,
    RecordModuleDefinition,
} from '@/types';

type Props = {
    module: RecordModuleDefinition;
    records: RecordList;
    query: RecordListQuery;
    can: { create: boolean };
    addons: RecordIndexAddons; // 見出しの操作(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)
    addonBadges: Record<string, RecordAddonBadge[]>; // 行の id → 札(同じ 2.5。JSON のオブジェクト)
};

/** 検索・並び替え・ページを変えたときに取り直す props(定義 module は取り直さない。行を入れ替えるので札も = 業務の画面.md の 2.1) */
const LIST_PROPS = ['records', 'query', 'addonBadges'];

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
 * 一覧の見出し。管理者が書いた業務モジュールの説明は名前の横の「?」に入れ、空なら出さない
 * (docs の 設計/画面の意匠/README.md 方針 9(d)、設計/業務データの入れ物/画面/業務の画面.md の 2.1)。
 */
function ListTitle({ module }: { module: RecordModuleDefinition }) {
    const description = moduleDescriptionHelp(module.description);

    return (
        <div className="flex items-center gap-1.5">
            <h1 className="text-2xl font-semibold">{module.name}</h1>
            {description !== null && (
                <HeadingHelp label={module.name}>{description}</HeadingHelp>
            )}
        </div>
    );
}

export default function Index(props: Props) {
    const { module, records, query, can } = props;
    const listed = module.fields.filter((field) => field.isListed);
    const searchable = module.fields.filter((field) => field.isSearchable);
    const currentFilter = query.filters as QueryParams;
    // 検索(「検索」「条件を解除」)の訪問が成功するたびに、検索欄をサーバーが解釈した条件で作り直す(捨てられた条件を欄に残さない = 修正方針 B3)
    const [searchVersion, setSearchVersion] = useState(0);
    const refresh = () => setSearchVersion((version) => version + 1);
    // 並び替え・ページの訪問は今の条件をそのまま送るので、欄を作り直さない(未送信の入力を残す = 修正方針 D1)
    const sortBy = (sort: string) => visit(module.code, currentFilter, sort);

    return (
        <>
            <Head title={module.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <ListTitle module={module} />
                    <div className="flex items-center gap-2">
                        <RecordAddonActions
                            actions={props.addons.actions}
                            held={false}
                        />
                        {can.create && (
                            <Button asChild>
                                <Link href={create(module.code)}>登録</Link>
                            </Button>
                        )}
                    </div>
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
                            <RecordListRows
                                moduleCode={module.code}
                                listed={listed}
                                records={records}
                                addonBadges={props.addonBadges}
                            />
                        </TableBody>
                    </Table>
                    <Pagination
                        list={records}
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

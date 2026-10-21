import { router, usePage } from '@inertiajs/react';
import { RecordShowTabs } from '@/components/RecordShowTabs';
import { RecordValue } from '@/components/RecordValue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ownValue } from '@/lib/Records/recordAddons';
import { isWideField } from '@/lib/recordForm';
import {
    detailsTabKey,
    FIELDS_TAB,
    personText,
    selectedTabKey,
    urlWithQuery,
} from '@/lib/recordShow';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import type {
    RecordDetail,
    RecordDetailRow,
    RecordFieldDefinition,
    RecordModuleDefinition,
} from '@/types';

/**
 * 明細の項目の表(列 = 明細のモジュールの項目)。行が無ければ「明細はありません」。
 */
function DetailsTable({
    field,
    rows,
}: {
    field: RecordFieldDefinition;
    rows: RecordDetailRow[];
}) {
    const columns = field.childModule?.fields ?? [];

    return rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">明細はありません</p>
    ) : (
        <Table>
            <TableHeader>
                <TableRow>
                    {columns.map((column) => (
                        <TableHead key={column.code}>{column.name}</TableHead>
                    ))}
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow key={row.id}>
                        {columns.map((column) => (
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
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * 項目の欄 1 つ(名前の下に値)。note = 後ろのモジュールが足す、欄の上の 1 行(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。
 * 注は欄の上に見せ、dl の並び(dt の後の dd)は崩さない。
 */
function FieldItem({
    field,
    record,
    note,
}: {
    field: RecordFieldDefinition;
    record: RecordDetail;
    note: string | undefined;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1',
                isWideField(field) && 'col-span-full',
            )}
        >
            <dt className="text-xs text-muted-foreground">{field.name}</dt>
            {note !== undefined && (
                <dd className="order-first text-xs text-muted-foreground">
                    {note}
                </dd>
            )}
            <dd className="min-w-0">
                <RecordValue
                    field={field}
                    value={record.values[field.code] ?? null}
                />
            </dd>
        </div>
    );
}

/**
 * 詳細タブ: 項目の格子(列は左の面の幅の段階 = 帯だけで 3 列、パネルを開くと 2 列 =
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 1 節。複数行は全幅)と、作成・更新の日時と人。notes = 後ろのモジュールが足す、項目の欄の上の 1 行(項目のコード → 文)。
 */
function FieldsTab({
    module,
    record,
    notes,
}: {
    module: RecordModuleDefinition;
    record: RecordDetail;
    notes: Record<string, string>;
}) {
    const fields = module.fields.filter((field) => field.type !== 'details');
    const authors = [
        ['作成', record.createdAt, record.createdBy],
        ['更新', record.updatedAt, record.updatedBy],
    ] as const;

    return (
        <div className="flex flex-col gap-5">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm @md:grid-cols-2 @3xl:grid-cols-3">
                {fields.map((field) => (
                    <FieldItem
                        key={field.code}
                        field={field}
                        record={record}
                        note={ownValue(notes, field.code)}
                    />
                ))}
            </dl>
            <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {authors.map(([label, at, by]) => [
                    <dt key={`${label}-t`}>{label}</dt>,
                    <dd key={`${label}-d`}>
                        <span className="font-num tabular-nums">
                            {at === null ? EMPTY_VALUE : formatDatetime(at)}
                        </span>{' '}
                        {personText(by)}
                    </dd>,
                ])}
            </dl>
        </div>
    );
}

/**
 * 左のタブ(詳細 / 明細の札)。選んだタブは URL のクエリ tab に映す(中身は props にあるので URL だけを置き換える = 詳細の画面/README.md の 3 節)。
 */
export function RecordDetailTabs({
    module,
    record,
    fieldNotes = {},
}: {
    module: RecordModuleDefinition;
    record: RecordDetail;
    fieldNotes?: Record<string, string>;
}) {
    const { url } = usePage();
    const detailsFields = module.fields.filter(
        (field) => field.type === 'details',
    );
    const tabs = [
        { key: FIELDS_TAB, label: '詳細', count: null },
        ...detailsFields.map((field) => ({
            key: detailsTabKey(field.code),
            label: field.name,
            count: (record.details[field.code] ?? []).length,
        })),
    ];
    const selected = selectedTabKey(
        url,
        tabs.map((tab) => tab.key),
    );
    const selectedField = detailsFields.find(
        (field) => detailsTabKey(field.code) === selected,
    );
    const select = (key: string) =>
        router.replace({
            url: urlWithQuery(url, { tab: key }),
            preserveState: true,
            preserveScroll: true,
        });

    return (
        <RecordShowTabs tabs={tabs} selected={selected} onSelect={select}>
            {selectedField === undefined ? (
                <FieldsTab module={module} record={record} notes={fieldNotes} />
            ) : (
                <DetailsTable
                    field={selectedField}
                    rows={record.details[selectedField.code] ?? []}
                />
            )}
        </RecordShowTabs>
    );
}

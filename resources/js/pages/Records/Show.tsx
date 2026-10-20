import { Head, Link } from '@inertiajs/react';
import { ConfirmDeleteDialog } from '@/components/ConfirmDeleteDialog';
import { RecordHoldNotice } from '@/components/Records/RecordHoldNotice';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { isWideField } from '@/lib/recordForm';
import { EMPTY_VALUE, formatDatetime, recordHeading } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import { destroy, edit, index, show } from '@/routes/records/data';
import type {
    RecordDetail,
    RecordDetailRow,
    RecordFieldDefinition,
    RecordHold,
    RecordModuleDefinition,
    RecordReferenceValue,
} from '@/types';

type Props = {
    module: RecordModuleDefinition;
    record: RecordDetail;
    /** 持ち主があれば can の 2 つは偽(画面/業務の画面.md 2.3) */
    can: { update: boolean; delete: boolean };
    hold: RecordHold | null;
};

/**
 * 作成者・更新者(ゲストは「ゲスト」。名称が無ければコード)。
 */
function authorOf(user: RecordReferenceValue | null) {
    return user === null
        ? 'ゲスト'
        : (user.label ?? user.code ?? `#${user.id}`);
}

/** 札「詳細」(本体の項目)の値。明細の札は details-<項目のコード>(コードは英小文字で始まるので重ならない) */
const MAIN_TAB = 'main';

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

export default function Show({ module, record, can, hold }: Props) {
    const heading = recordHeading(module, record);
    const plainFields = module.fields.filter(
        (field) => field.type !== 'details',
    );
    const detailsFields = module.fields.filter(
        (field) => field.type === 'details',
    );

    return (
        <>
            <Head title={heading} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-6">
                    <div className="flex items-center justify-between gap-4">
                        <h1 className="text-2xl font-semibold">{heading}</h1>
                        {(can.update || can.delete) && (
                            <div className="flex items-center gap-2">
                                {can.update && (
                                    <Button asChild>
                                        <Link
                                            href={edit({
                                                module: module.code,
                                                record: record.id,
                                            })}
                                        >
                                            編集
                                        </Link>
                                    </Button>
                                )}
                                {can.delete && (
                                    <ConfirmDeleteDialog
                                        triggerVariant="destructive"
                                        title="レコードの削除"
                                        description={`${heading}を削除しますか。${detailsFields.length > 0 ? '明細も一緒に削除されます。' : ''}この操作は取り消せません。`}
                                        action={destroy({
                                            module: module.code,
                                            record: record.id,
                                        })}
                                    />
                                )}
                            </div>
                        )}
                    </div>
                    {hold !== null && <RecordHoldNotice hold={hold} />}
                    <section
                        aria-label={`${heading}の値`}
                        className="rounded-xl border bg-card p-5 text-card-foreground"
                    >
                        {/* 札の切り替えは画面の中だけ(URL・localStorage に残さない。訪問のたびに「詳細」= 画面/業務の画面.md 2.3) */}
                        <Tabs defaultValue={MAIN_TAB} className="gap-4">
                            <TabsList
                                variant="line"
                                className="h-auto max-w-full flex-wrap justify-start"
                            >
                                <TabsTrigger
                                    value={MAIN_TAB}
                                    className="flex-none"
                                >
                                    詳細
                                </TabsTrigger>
                                {detailsFields.map((field) => (
                                    <TabsTrigger
                                        key={field.code}
                                        value={`details-${field.code}`}
                                        className="flex-none"
                                    >
                                        {`${field.name} (${(record.details[field.code] ?? []).length})`}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                            <TabsContent value={MAIN_TAB}>
                                <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                                    {plainFields.map((field) => (
                                        <div
                                            key={field.code}
                                            className={cn(
                                                'flex min-w-0 flex-col gap-1',
                                                isWideField(field) &&
                                                    'md:col-span-2',
                                            )}
                                        >
                                            <dt className="text-xs text-muted-foreground">
                                                {field.name}
                                            </dt>
                                            <dd>
                                                <RecordValue
                                                    field={field}
                                                    value={
                                                        record.values[
                                                            field.code
                                                        ] ?? null
                                                    }
                                                />
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </TabsContent>
                            {detailsFields.map((field) => (
                                <TabsContent
                                    key={field.code}
                                    value={`details-${field.code}`}
                                >
                                    <DetailsTable
                                        field={field}
                                        rows={record.details[field.code] ?? []}
                                    />
                                </TabsContent>
                            ))}
                        </Tabs>
                    </section>
                    <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <dt>作成</dt>
                        <dd>
                            <span className="font-num tabular-nums">
                                {record.createdAt === null
                                    ? EMPTY_VALUE
                                    : formatDatetime(record.createdAt)}
                            </span>{' '}
                            {authorOf(record.createdBy)}
                        </dd>
                        <dt>更新</dt>
                        <dd>
                            <span className="font-num tabular-nums">
                                {record.updatedAt === null
                                    ? EMPTY_VALUE
                                    : formatDatetime(record.updatedAt)}
                            </span>{' '}
                            {authorOf(record.updatedBy)}
                        </dd>
                    </dl>
                </div>
            </div>
        </>
    );
}

Show.layout = (props: Props) => ({
    breadcrumbs: [
        { title: props.module.name, href: index(props.module.code) },
        {
            title: recordHeading(props.module, props.record),
            href: show({ module: props.module.code, record: props.record.id }),
        },
    ],
});
